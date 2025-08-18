<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class YouTubeService
{
    private $httpClient;
    private $apiKey;

    public function __construct(HttpClientInterface $httpClient, string $apiKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
    }

    public function getRecommendations(string $query): array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://youtube.googleapis.com/youtube/v3/search', [
                'query' => [
                    'part' => 'snippet',
                    'q' => $query . ' tutorial',
                    'type' => 'video',
                    'maxResults' => 5,
                    'key' => $this->apiKey,
                ]
            ]);

            $data = $response->toArray();
            return array_map(function ($item) {
                return [
                    'videoId' => $item['id']['videoId'],
                    'title' => $item['snippet']['title'],
                    'description' => $item['snippet']['description'],
                    'thumbnail' => $item['snippet']['thumbnails']['high']['url']
                ];
            }, $data['items'] ?? []);
        } catch (\Exception $e) {
            // Log the error if necessary
            return [];
        }
    }
}