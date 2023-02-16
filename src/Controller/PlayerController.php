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
            WITH leaderboard AS (
                SELECT
                    RANK() OVER (
                        ORDER BY sprint_score DESC
                    ) AS rank,
                    user_scores.steam_id,
                    name,
                    holdboost_score,
                    sprint_count,
                    ROUND(sprint_score) AS sprint_score
                FROM user_scores
                WHERE sprint_score > 0
                GROUP BY user_scores.steam_id
            )

            SELECT * FROM leaderboard
            WHERE rank < 1001
            ORDER BY sprint_score DESC
        ');

        $firsts = $this->db->fetchAllAssociative('
            SELECT
                RANK() OVER (
                    ORDER BY COUNT(*) DESC
                ) AS rank,
                COUNT(*) firsts,
                user_scores.steam_id,
                user_scores.name
            FROM weighted_sprint_leaderboard
            INNER JOIN user_scores
            ON user_scores.steam_id = weighted_sprint_leaderboard.steam_id
            INNER JOIN workshop_levels
            ON workshop_levels.id = weighted_sprint_leaderboard.level_id
            WHERE weighted_sprint_leaderboard.rank = 1
            AND workshop_levels.is_sprint
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
                WITH ranks AS (
                    SELECT
                        steam_id,
                        RANK() OVER (
                            ORDER BY sprint_score DESC
                        ) AS sprint_rank
                    FROM user_scores
                )

                SELECT
                    sprint_rank,
                    user_scores.steam_id,
                    name,
                    holdboost_score,
                    sprint_count,
                    ROUND(sprint_score) AS sprint_score
                FROM user_scores
                INNER JOIN ranks
                ON ranks.steam_id = user_scores.steam_id
                WHERE user_scores.steam_id = ?
            ',
            [$id]
        );

        $tracks = $this->db->fetchAllAssociative('
                SELECT
                  workshop_levels.id,
                  workshop_levels.name,
                  weighted_sprint_leaderboard.rank,
                  weighted_sprint_leaderboard.time,
                  weighted_sprint_leaderboard.workshop_score,
                  ROUND(1000.0 * sprint_track_weight) AS sprint_track_weight,
                  ROUND(CAST(weighted_sprint_leaderboard.workshop_score_weighted as numeric), 3) AS workshop_score_weighted
                FROM workshop_levels
                INNER JOIN weighted_sprint_leaderboard
                ON weighted_sprint_leaderboard.level_id = workshop_levels.id
                WHERE weighted_sprint_leaderboard.steam_id = ?
                AND workshop_levels.is_sprint
                ORDER BY sprint_track_weight DESC
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
