<?php

namespace DHB\Controller;

use DHB\TimeFormatter;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

class TrackController
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
        $tracks = $this->db->fetchAllAssociative('
            WITH levels AS (
                SELECT
                    id,
                    name,
                    '.$type.'_finished_count AS finished_count,
                    '.$type.'_track_weight AS track_weight
                FROM workshop_levels
                WHERE is_'.$type.'
            )

            SELECT
                RANK() OVER (
                    ORDER BY track_weight DESC
                ) AS rank,
                id,
                name,
                finished_count,
                ROUND(1000.0 * track_weight) AS track_weight
            FROM levels
            ORDER BY track_weight DESC
        ');

        $out = $this->twig->render('tracks.twig', [
            'title' => $this->lb_types[$type]['label'].' tracks',
            'tracks' => $tracks,
            'type' => $this->lb_types[$type],
        ]);

        return new Response($out);
    }

    public function popular(Request $req, string $type): Response
    {
        $tracks = $this->db->fetchAllAssociative('
            WITH levels AS (
                SELECT
                    id,
                    name,
                    '.$type.'_finished_count AS finished_count,
                    '.$type.'_track_weight AS track_weight
                FROM workshop_levels
                WHERE is_'.$type.'
            )

            SELECT
                RANK() OVER (
                    ORDER BY finished_count DESC
                ) AS rank,
                id,
                name,
                finished_count,
                ROUND(1000.0 * track_weight) AS track_weight
            FROM levels
            ORDER BY finished_count DESC
        ');

        $out = $this->twig->render('tracks.twig', [
            'title' => 'Popular '.strtolower($this->lb_types[$type]['label']).' tracks',
            'tracks' => $tracks,
            'type' => $this->lb_types[$type],
        ]);

        return new Response($out);
    }

    public function show(Request $req, string $type, int $id): Response
    {
        $track = $this->db->fetchAssociative('
                WITH levels AS (
                    SELECT
                        id,
                        name,
                        is_sprint,
                        is_challenge,
                        is_stunt,
                        '.$type.'_finished_count AS finished_count,
                        '.$type.'_track_weight AS track_weight
                    FROM workshop_levels
                    WHERE is_'.$type.'
                ),
                t AS (
                    SELECT
                        RANK() OVER (
                            ORDER BY track_weight DESC
                        ) weight_rank,
                        RANK() OVER (
                            ORDER BY finished_count DESC
                        ) popular_rank,
                        id,
                        name,
                        is_sprint,
                        is_challenge,
                        is_stunt,
                        finished_count AS finished_count,
                        ROUND(1000.0 * track_weight) AS track_weight
                    FROM levels
                )

                SELECT * FROM t
                WHERE id = ?
            ',
            [$id]
        );

        if (!$track) {
            throw new NotFoundHttpException('Track not found');
        }

        $lb = $this->db->fetchAllAssociative('
                WITH lb AS (
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
                    lb.rank,
                    lb.scorefield,
                    users.steam_id,
                    users.name,
                    lb.workshop_score,
                    lb.workshop_score_weighted
                FROM lb
                INNER JOIN users
                ON users.steam_id = lb.steam_id
                WHERE lb.level_id = ?
                ORDER BY lb.rank ASC
            ',
            [$id]
        );

        if ($this->lb_types[$type]['score_field'] === 'time') {
            foreach ($lb as $index => $row) {
                $lb[$index]['scorefield'] = TimeFormatter::format($row['scorefield']);
            }
        } else {
            foreach ($lb as $index => $row) {
                $lb[$index]['scorefield'] = number_format($row['scorefield']);
            }
        }

        $out = $this->twig->render('track.twig', [
            'title' => $this->lb_types[$type]['label'].' track '.$track['name'],
            'track' => $track,
            'leaderboard' => $lb,
            'type' => $this->lb_types[$type],
            'types' => $this->lb_types,
        ]);

        return new Response($out);
    }
}
