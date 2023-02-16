<?php

namespace DHB\Controller;

use DHB\TimeFormatter;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class PlayerController
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
        $lb = $this->db->fetchAllAssociative('
            SELECT
                rank,
                steam_id,
                name,
                holdboost_score,
                workshop_count,
                ROUND(workshop_score_final) AS workshop_final_score
            FROM user_scores
            WHERE workshop_score > 0
            AND rank < 1001
            ORDER BY workshop_final_score DESC
        ');

        $firsts = $this->db->fetchAllAssociative('
            SELECT
                RANK() OVER (
                    ORDER BY COUNT(*) DESC
                ) AS rank,
                COUNT(*) firsts,
                user_scores.steam_id,
                user_scores.name
            FROM weighted_leaderboard
            INNER JOIN user_scores
            ON user_scores.steam_id = weighted_leaderboard.steam_id
            WHERE weighted_leaderboard.rank = 1
            GROUP BY user_scores.steam_id
            ORDER BY firsts DESC
        ');

        $firsts_trunced = [];
        $trunced_sum = [
            'rank' => '',
            'steam_id' => null,
            'name' => 'Other',
            'firsts' => 0,
        ];
        $pie_sum = 0;
        $piedata = [
            'labels' => [],
            'datasets' => [[
                'data' => [],
                'backgroundColor' => [],
            ]],
        ];

        foreach ($firsts as $index => $row) {
            if ($row['rank'] <= 30) {
                $firsts_trunced[$index] = $row;
            } else {
                $trunced_sum['firsts'] += $row['firsts'];
            }

            if ($row['rank'] <= 20) {
                $piedata['labels'][] = $row['name'];
                $piedata['datasets'][0]['data'][] = $row['firsts'];
                $piedata['datasets'][0]['backgroundColor'][] = '#'.substr(dechex($row['steam_id']), -6);
            } else {
                $pie_sum += $row['firsts'];
            }
        }

        $firsts_trunced[] = $trunced_sum;
        $piedata['labels'][] = 'Other';
        $piedata['datasets'][0]['backgroundColor'][] = '#444';
        $piedata['datasets'][0]['data'][] += $pie_sum;

        $out = $this->twig->render('index.twig', [
            'leaderboard' => $lb,
            'firsts' => $firsts_trunced,
            'piedata' => $piedata,
        ]);

        return new Response($out);
    }

    public function show(Request $req, $id): Response
    {
        $player = $this->db->fetchAssociative('
                SELECT
                    rank,
                    steam_id,
                    name,
                    holdboost_score,
                    workshop_count,
                    ROUND(workshop_score_final) AS workshop_final_score
                FROM user_scores
                WHERE steam_id = ?
            ',
            [$id]
        );

        $tracks = $this->db->fetchAllAssociative('
                SELECT
                  workshop_weights.id,
                  workshop_weights.name,
                  weighted_leaderboard.rank AS place,
                  weighted_leaderboard.time,
                  weighted_leaderboard.workshop_score,
                  ROUND(1000.0 * track_weight) AS track_weight,
                  ROUND(CAST(weighted_leaderboard.workshop_score_weighted as numeric), 3) AS workshop_score_weighted
                FROM workshop_weights
                INNER JOIN weighted_leaderboard
                ON weighted_leaderboard.level_id = workshop_weights.id
                WHERE weighted_leaderboard.steam_id = ?
                ORDER BY track_weight DESC
            ',
            [$id]
        );

        foreach ($tracks as $index => $track) {
            $tracks[$index]['time'] = TimeFormatter::format($track['time']);
        }

        $out = $this->twig->render('player.twig', [
            'title' => $player['name'].' stats',
            'player' => $player,
            'tracks' => $tracks,
        ]);

        return new Response($out);
    }
}
