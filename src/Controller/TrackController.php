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
                    ORDER BY sprint_track_weight DESC
                ) AS rank,
                id,
                name,
                sprint_finished_count AS finished_count,
                ROUND(1000.0 * sprint_track_weight) AS track_weight
            FROM workshop_levels
            WHERE is_sprint
            ORDER BY sprint_track_weight DESC
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
                    ORDER BY sprint_finished_count DESC
                ) AS rank,
                id,
                name,
                sprint_finished_count AS finished_count,
                ROUND(1000.0 * sprint_track_weight) AS track_weight
            FROM workshop_levels
            WHERE is_sprint
            ORDER BY sprint_finished_count DESC
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
                            ORDER BY sprint_track_weight DESC
                        ) weight_rank,
                        RANK() OVER (
                            ORDER BY sprint_finished_count DESC
                        ) popular_rank,
                        id,
                        name,
                        sprint_finished_count AS finished_count,
                        ROUND(1000.0 * sprint_track_weight) AS track_weight
                    FROM workshop_levels
                )
                SELECT * FROM t
                WHERE id = ?
            ',
            [$id]
        );

        $lb = $this->db->fetchAllAssociative('
                SELECT
                    weighted_sprint_leaderboard.rank,
                    weighted_sprint_leaderboard.time,
                    user_scores.steam_id,
                    user_scores.name,
                    weighted_sprint_leaderboard.workshop_score,
                    weighted_sprint_leaderboard.workshop_score_weighted
                FROM weighted_sprint_leaderboard
                INNER JOIN user_scores
                ON user_scores.steam_id = weighted_sprint_leaderboard.steam_id
                WHERE weighted_sprint_leaderboard.level_id = ?
                ORDER BY weighted_sprint_leaderboard.rank ASC
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
