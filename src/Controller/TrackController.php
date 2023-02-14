<?php

namespace DHB\Controller;

use DHB\TimeFormatter;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class TrackController
{
    private $db;
    private $twig;

    public function __construct(Connection $db, Environment $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function list(Request $req): Response
    {
        $tracks = $this->db->fetchAllAssociative('
            SELECT
                RANK() OVER (
                    ORDER BY track_weight DESC
                ) AS rank,
                id,
                name,
                finished_count,
                ROUND(1000.0 * track_weight) AS track_weight
            FROM workshop_weights
            ORDER BY track_weight DESC
        ');

        $out = $this->twig->render('tracks.twig', [
            'title' => 'Tracks',
            'tracks' => $tracks,
        ]);

        return new Response($out);
    }

    public function popular(Request $req): Response
    {
        $tracks = $this->db->fetchAllAssociative('
            SELECT
                RANK() OVER (
                    ORDER BY finished_count DESC
                ) AS rank,
                id,
                name,
                finished_count,
                ROUND(1000.0 * track_weight) AS track_weight
            FROM workshop_weights
            ORDER BY finished_count DESC
        ');

        $out = $this->twig->render('tracks.twig', [
            'title' => 'Popular tracks',
            'tracks' => $tracks,
        ]);

        return new Response($out);
    }

    public function show(Request $req, $id): Response
    {
        $track = $this->db->fetchAssociative('
                WITH t AS (
                    SELECT
                        RANK() OVER (
                            ORDER BY track_weight DESC
                        ) rank,
                        id,
                        name,
                        finished_count,
                        ROUND(1000.0 * track_weight) AS track_weight
                    FROM workshop_weights
                )
                SELECT * FROM t
                WHERE id = ?
            ',
            [$id]
        );

        $lb = $this->db->fetchAllAssociative('
                SELECT
                    weighted_leaderboard.rank,
                    weighted_leaderboard.time,
                    user_weights.steam_id,
                    user_weights.name,
                    weighted_leaderboard.workshop_score,
                    weighted_leaderboard.workshop_score_weighted
                FROM weighted_leaderboard
                INNER JOIN user_weights
                ON user_weights.steam_id = weighted_leaderboard.steam_id
                WHERE weighted_leaderboard.level_id = ?
                ORDER BY rank ASC
            ',
            [$id]
        );

        foreach ($lb as $index => $row) {
            $lb[$index]['time'] = TimeFormatter::format($row['time']);
        }

        $out = $this->twig->render('track.twig', [
            'title' => 'Track '.$track['name'],
            'track' => $track,
            'leaderboard' => $lb,
        ]);

        return new Response($out);
    }
}
