<?php

namespace HoldGrip\Controller;

use Doctrine\DBAL\Connection;
use HoldGrip\NotFoundException;
use HoldGrip\TimeFormatter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class TrackController
{
    const RANK_WEIGHT = 'weight';
    const RANK_COMPLETED = 'completed';
    const RANK_POPULAR = 'popular';

    private $db;
    private $twig;
    private $lb_types;

    public function __construct(Connection $db, Environment $twig, array $lb_types)
    {
        $this->db = $db;
        $this->twig = $twig;
        $this->lb_types = $lb_types;
    }

    public function list(Request $req, string $type, string $ranking): Response
    {
        $typelabel = $this->lb_types[$type]['label'];

        [$rank_field, $title] = match ($ranking) {
            self::RANK_WEIGHT => ['track_weight', $typelabel.' tracks by weight'],
            self::RANK_COMPLETED => ['finished_count', $typelabel.' tracks by completions'],
            self::RANK_POPULAR => ['popularity', $typelabel.' tracks by popularity'],
            default => throw new InvalidArgumentException(),
        };

        $tracks = $this->db->fetchAllAssociative('
            WITH levels AS (
                SELECT
                    id,
                    name,
                    votes_up,
                    votes_down,
                    popularity,
                    '.$type.'_finished_count AS finished_count,
                    '.$type.'_track_weight AS track_weight
                FROM workshop_levels
                WHERE is_'.$type.'
            ),
            rankedLevels AS (
                SELECT
                    id,
                    name,
                    votes_up,
                    votes_down,
                    popularity,
                    finished_count,
                    track_weight,
                    '.$rank_field.' AS rank_field
                FROM levels
            )

            SELECT
                CASE WHEN rank_field
                    THEN RANK() OVER (ORDER BY rank_field DESC)
                    ELSE NULL
                END AS rank,
                id,
                name,
                votes_up,
                votes_down,
                finished_count,
                ROUND(1000.0 * track_weight) AS track_weight
            FROM rankedLevels
            ORDER BY rank_field DESC
        ');

        $out = $this->twig->render('tracks.twig', [
            'title' => $title,
            'ranking' => $ranking,
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
                        time_created,
                        votes_up,
                        votes_down,
                        popularity,
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
                        ) finished_rank,
                        RANK() OVER (
                            ORDER BY popularity DESC
                        ) popular_rank,
                        id,
                        name,
                        time_created,
                        votes_up,
                        votes_down,
                        is_sprint,
                        is_challenge,
                        is_stunt,
                        finished_count,
                        ROUND(1000.0 * track_weight) AS track_weight
                    FROM levels
                )

                SELECT * FROM t
                WHERE id = ?
            ',
            [$id]
        );

        if (!$track) {
            throw new NotFoundException('Track not found');
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
