<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service de géocodage utilisant Nominatim (OpenStreetMap)
 */
class GeocodingService
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    /**
     * Convertir une adresse en coordonnées GPS
     */
    public function geocode(string $address): ?array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
                'query' => [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    // Pas de restriction de pays pour supporter les trajets internationaux
                ],
                'headers' => [
                    'User-Agent' => 'WayZo VTC App/1.0',
                ],
            ]);

            $data = $response->toArray();

            if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
                return [
                    'lat' => (float) $data[0]['lat'],
                    'lng' => (float) $data[0]['lon'],
                    'displayName' => $data[0]['display_name'] ?? $address,
                ];
            }

            return null;
        } catch (\Exception $e) {
            // Log l'erreur mais ne pas bloquer
            return null;
        }
    }

    /**
     * Géocoder le départ et la destination d'une course
     */
    public function geocodeRide(string $depart, string $destination): array
    {
        $departure = $this->geocode($depart);
        $arrival = $this->geocode($destination);
        
        $distance = null;
        if ($departure && $arrival) {
            $distance = $this->calculateDistance(
                $departure['lat'], 
                $departure['lng'], 
                $arrival['lat'], 
                $arrival['lng']
            );
        }
        
        return [
            'departure' => $departure,
            'arrival' => $arrival,
            'distance' => $distance,
        ];
    }

    /**
     * Calculer la distance en km entre deux points GPS (formule de Haversine)
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // Rayon de la Terre en km

        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        // Ajouter 20% pour les routes (approximation)
        return round($distance * 1.2, 1);
    }
}
