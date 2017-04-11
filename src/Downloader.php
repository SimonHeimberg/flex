<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Cache;
use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Downloader
{
    private const ENDPOINT = 'https://flex.symfony.com';

    private $io;
    private $sess;
    private $cache;
    private $rfs;
    private $degradedMode = false;
    private $endpoint;
    private $flexId;

    public function __construct(Composer $composer, IoInterface $io)
    {
        $this->io = $io;
        $config = $composer->getConfig();
        $this->endpoint = rtrim(getenv('FLEX_ENDPOINT') ?: self::ENDPOINT, '/');
        $this->rfs = Factory::createRemoteFilesystem($io, $config);
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->endpoint));
        $this->sess = bin2hex(random_bytes(16));
    }

    public function setFlexId($id = null)
    {
        $this->flexId = $id;
    }

    /**
     * Decodes a JSON HTTP response body.
     *
     * @param string $path    The path to get on the Flex server
     * @param array  $headers An array of HTTP headers
     */
    public function get($path, array $headers = []): ?Response
    {
        $headers[] = 'Package-Session: '.$this->sess;
        $url = $this->endpoint.'/'.ltrim($path, '/');
        $cacheKey = ltrim($path, '/');

        try {
            if ($contents = $this->cache->read($cacheKey)) {
                $cachedResponse = Response::fromJson(json_decode($contents, true));
                if ($lastModified = $cachedResponse->getHeader('last-modified')) {
                    $response = $this->fetchFileIfLastModified($url, $cacheKey, $lastModified, $headers);

                    return null === $response ? $cachedResponse : $response;
                }
            }

            return $this->fetchFile($url, $cacheKey, $headers);
        } catch (TransportException $e) {
            if (404 === $e->getStatusCode()) {
                return null;
            }

            throw $e;
        }
    }

    private function fetchFile($url, $cacheKey, $headers): Response
    {
        $options = $this->getOptions($headers);
        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($this->endpoint, $url, false, $options);

                return $this->parseJson($json, $url, $cacheKey);
            } catch (\Exception $e) {
                if ($e instanceof TransportException && 404 === $e->getStatusCode()) {
                    throw $e;
                }

                if ($retries) {
                    usleep(100000);
                    continue;
                }

                if ($contents = $this->cache->read($cacheKey)) {
                    $this->switchToDegradedMode($e, $url);

                    return Response::fromJson(JsonFile::parseJson($contents, $this->cache->getRoot().$cacheKey));
                }

                throw $e;
            }
        }
    }

    private function fetchFileIfLastModified($url, $cacheKey, $lastModifiedTime, $headers): ?Response
    {
        $headers[] = 'If-Modified-Since: '.$lastModifiedTime;
        $options = $this->getOptions($headers);
        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($this->endpoint, $url, false, $options);
                if (304 === $this->rfs->findStatusCode($this->rfs->getLastHeaders())) {
                    return null;
                }

                return $this->parseJson($json, $url, $cacheKey);
            } catch (\Exception $e) {
                if ($e instanceof TransportException && 404 === $e->getStatusCode()) {
                    throw $e;
                }

                if ($retries) {
                    usleep(100000);
                    continue;
                }

                $this->switchToDegradedMode($e, $url);

                return null;
            }
        }
    }

    private function parseJson($json, $url, $cacheKey): Response
    {
        $data = JsonFile::parseJson($json, $url);
        if (!empty($data['warning'])) {
            $this->io->writeError('<warning>Warning from '.$url.': '.$data['warning'].'</warning>');
        }
        if (!empty($data['info'])) {
            $this->io->writeError('<info>Info from '.$url.': '.$data['info'].'</info>');
        }

        $response = new Response($data, $this->rfs->getLastHeaders());
        if (null !== $response->getHeader('last-modified')) {
            $this->cache->write($cacheKey, json_encode($response));
        }

        return $response;
    }

    private function switchToDegradedMode(\Exception $e, $url)
    {
        if (!$this->degradedMode) {
            $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
            $this->io->writeError('<warning>'.$url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
        }
        $this->degradedMode = true;
    }

    private function getOptions(array $headers)
    {
        $options = ['http' => ['header' => $headers]];

        if ($this->flexId) {
            $options['http']['header'][] = 'Project: '.$this->flexId;
        }

        return $options;
    }
}
