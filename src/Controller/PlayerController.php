<?php

namespace HoldGrip\Controller;

use Doctrine\DBAL\Connection;
use HoldGrip\NotFoundException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class PlayerController
{
    private $db;
    private $twig;
    private $lb_types;

    public function __construct(Connection $db, Environment $twig, array $lb_types)
    {
        $this->db = $db;
        $this->twig = $twig;
        $this->lb_types = $lb_types;
    }

    public function list(Request $req, string $type): Response
    {
        $lb = $this->db->fetchAllAssociative('
            SELECT
                u.steam_id,
                u.name,
                u.holdboost_score,
                u.'.$type.'_count AS count,
                ROUND(u.'.$type.'_score) AS score,
                ur.'.$type.'_rank AS rank
            FROM users AS u
            INNER JOIN user_ranks AS ur
            ON u.steam_id = ur.steam_id
            WHERE '.$type.'_rank < 1001
            AND '.$type.'_score > 0
            ORDER BY score DESC
            LIMIT 1100
        ');

        $firsts = $this->db->fetchAllAssociative('
            SELECT
                RANK() OVER (
                    ORDER BY '.$type.'_firsts DESC
                ) AS rank,
                '.$type.'_firsts AS firsts,
                u.steam_id,
                u.name
            FROM users AS u
            INNER JOIN user_ranks AS ur
            ON ur.steam_id = u.steam_id
            WHERE '.$type.'_firsts > 0
            GROUP BY u.steam_id
            ORDER BY firsts DESC, u.steam_id
        ');

        $firsts_trunced = [];
        $trunced_sum = 0;
        $pie_sum = 0;
        $piedata = [
            'labels' => [],
            'datasets' => [[
                'data' => [],
                'backgroundColor' => [],
            ]],
        ];

        foreach ($firsts as $index => $row) {
            if ($row['rank'] <= 30 && $row['firsts'] > 1) {
                $firsts_trunced[$index] = $row;
            } else {
                $trunced_sum += $row['firsts'];
            }

            if ($row['rank'] <= 20 && $row['firsts'] >= 5) {
                $piedata['labels'][] = $row['name'];
                $piedata['datasets'][0]['data'][] = $row['firsts'];
                $piedata['datasets'][0]['backgroundColor'][] = '#'.substr(dechex($row['steam_id']), -6);
            } else {
                $pie_sum += $row['firsts'];
            }
        }

        $firsts_trunced[] = [
            'rank' => '',
            'steam_id' => null,
            'name' => 'Other',
            'firsts' => $trunced_sum,
        ];
        $piedata['labels'][] = 'Other';
        $piedata['datasets'][0]['backgroundColor'][] = '#444';
        $piedata['datasets'][0]['data'][] = $pie_sum;

        $is_index = $req->getPathInfo() === '/';

        $out = $this->twig->render('index.twig', [
            'is_index' => $is_index,
            'title' => $is_index ? null : $this->lb_types[$type]['label'].' leaderboard',
            'leaderboard' => $lb,
            'firsts' => $firsts_trunced,
            'piedata' => $piedata,
            'type' => $this->lb_types[$type],
        ]);

        return new Response($out);
    }

    public function show(Request $req, int $id, string $type): Response
    {
        $player = $this->db->fetchAssociative('
                SELECT
                    users.steam_id,
                    name,
                    user_ranks.holdboost_rank,
                    holdboost_score,
                    user_ranks.sprint_rank,
                    sprint_count,
                    ROUND(sprint_score) AS sprint_score,
                    user_ranks.challenge_rank,
                    challenge_count,
                    ROUND(challenge_score) AS challenge_score,
                    user_ranks.stunt_rank,
                    stunt_count,
                    ROUND(stunt_score) AS stunt_score
                FROM users
                INNER JOIN user_ranks
                ON user_ranks.steam_id = users.steam_id
                WHERE users.steam_id = ?
            ',
            [$id]
        );

        if (!$player) {
            throw new NotFoundException('Player not found');
        }

        $tracks = $this->db->fetchAllAssociative('
                WITH levels AS (
                    SELECT
                        id,
                        name,
                        '.$type.'_track_weight AS track_weight
                    FROM workshop_levels
                    WHERE is_'.$type.'
                ),
                lb AS (
                    SELECT
                        level_id,
                        steam_id,
                        rank,
                        '.$this->lb_types[$type]['score_field'].' AS scorefield,
                        workshop_score,
                        workshop_score_weighted
                    FROM weighted_'.$type.'_leaderboard
                )

                SELECT
                  levels.id,
                  name,
                  lb.rank,
                  lb.scorefield,
                  lb.workshop_score,
                  ROUND(1000.0 * track_weight) AS track_weight,
                  ROUND(CAST(lb.workshop_score_weighted as numeric), 3) AS workshop_score_weighted
                FROM levels
                INNER JOIN lb
                ON lb.level_id = levels.id
                WHERE lb.steam_id = ?
                ORDER BY track_weight DESC
            ',
            [$id]
        );

        $out = $this->twig->render('player.twig', [
            'title' => $player['name'].' stats',
            'player' => $player,
            'tracks' => $tracks,
            'type' => $this->lb_types[$type],
            'types' => $this->lb_types,
        ]);

        return new Response($out);
    }
}
