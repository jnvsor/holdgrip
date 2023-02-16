<?php

namespace DHB\Controller;

use DHB\TimeFormatter;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
            WITH typelb AS (
                SELECT
                    steam_id,
                    name,
                    holdboost_score,
                    '.$type.'_count AS count,
                    '.$type.'_score AS score
                FROM users
            ),
            leaderboard AS (
                SELECT
                    RANK() OVER (
                        ORDER BY score DESC
                    ) AS rank,
                    steam_id,
                    name,
                    holdboost_score,
                    count,
                    ROUND(score) AS score
                FROM typelb
                WHERE score > 0
                GROUP BY steam_id
            )

            SELECT * FROM leaderboard
            WHERE rank < 1001
            ORDER BY score DESC
        ');

        $firsts = $this->db->fetchAllAssociative('
            WITH lb AS (
                SELECT * FROM weighted_'.$type.'_leaderboard
            ),
            levels AS (
                SELECT * FROM workshop_levels WHERE is_'.$type.'
            )

            SELECT
                RANK() OVER (
                    ORDER BY COUNT(*) DESC
                ) AS rank,
                COUNT(*) firsts,
                users.steam_id,
                users.name
            FROM lb
            INNER JOIN users
            ON users.steam_id = lb.steam_id
            INNER JOIN levels
            ON levels.id = lb.level_id
            WHERE lb.rank = 1
            GROUP BY users.steam_id
            ORDER BY firsts DESC
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
                WITH rank AS (
                    SELECT
                        steam_id,
                        RANK() OVER (
                            ORDER BY sprint_score DESC
                        ) AS sprint,
                        RANK() OVER (
                            ORDER BY challenge_score DESC
                        ) AS challenge,
                        RANK() OVER (
                            ORDER BY stunt_score DESC
                        ) AS stunt
                    FROM users
                )

                SELECT
                    users.steam_id,
                    name,
                    holdboost_score,
                    rank.sprint AS sprint_rank,
                    sprint_count,
                    ROUND(sprint_score) AS sprint_score,
                    rank.challenge AS challenge_rank,
                    challenge_count,
                    ROUND(challenge_score) AS challenge_score,
                    rank.stunt AS stunt_rank,
                    stunt_count,
                    ROUND(stunt_score) AS stunt_score
                FROM users
                INNER JOIN rank
                ON rank.steam_id = users.steam_id
                WHERE users.steam_id = ?
            ',
            [$id]
        );

        if (!$player) {
            throw new NotFoundHttpException('Player not found');
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

        if ($this->lb_types[$type]['score_field'] === 'time') {
            foreach ($tracks as $index => $track) {
                $tracks[$index]['scorefield'] = TimeFormatter::format($track['scorefield']);
            }
        } else {
            foreach ($tracks as $index => $track) {
                $tracks[$index]['scorefield'] = number_format($track['scorefield']);
            }
        }

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
