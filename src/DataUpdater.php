<?php

namespace DHB;

use Doctrine\DBAL\Connection;

class DataUpdater
{
    private $xdb;
    private $lb_types;

    public function __construct(Connection $external_db, array $lb_types)
    {
        $this->xdb = $external_db;
        $this->lb_types = $lb_types;
    }

    public function buildDb(Connection $db)
    {
        echo "Starting DB build".PHP_EOL;
        $start = microtime(true);

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE user_weights AS (
                WITH holdboost_points AS MATERIALIZED (
                    SELECT
                        rank,
                        ROUND(1000.0 * (1.0 - |/(1.0 - (((rank - 1.0)/1000.0) - 1.0)^2))) AS noodle_points
                    FROM generate_series(1, 1000) rank
                )

                SELECT
                    users.steam_id,
                    users.name,
                    SUM(COALESCE(points.noodle_points, 0)) AS weight
                FROM users
                INNER JOIN sprint_leaderboard_entries
                ON sprint_leaderboard_entries.steam_id = users.steam_id
                LEFT JOIN holdboost_points AS points
                ON sprint_leaderboard_entries.rank = points.rank
                WHERE level_id IN(SELECT id FROM official_levels WHERE is_sprint)
                GROUP BY users.steam_id
            )
        ');

        foreach ($this->lb_types as $type => $opts) {
            $this->xdb->executeUpdate('
                    CREATE TEMPORARY TABLE '.$type.'_stats AS (
                        WITH lb AS NOT MATERIALIZED (
                            SELECT * FROM '.$type.'_leaderboard_entries
                        ),
                        levels AS NOT MATERIALIZED (
                            SELECT id
                            FROM workshop_levels
                            WHERE is_'.$type.'
                        ),

                        tops AS (
                            SELECT
                                lb.level_id,
                                totals.c AS finished_count,
                                (AVG(COALESCE(user_weights.weight, 0)) * 0.75) +
                                    (SUM(COALESCE(user_weights.weight, 0)) / GREATEST(COUNT(*), :size) * 0.25) AS top_weight
                            FROM lb
                            INNER JOIN (
                                SELECT level_id, COUNT(*) c
                                FROM lb
                                GROUP BY lb.level_id
                            ) totals
                            ON totals.level_id = lb.level_id
                            LEFT JOIN user_weights
                            ON lb.steam_id = user_weights.steam_id
                            WHERE lb.rank <= :size
                            GROUP BY lb.level_id, totals.c
                        ),
                        stats AS (
                            SELECT
                                levels.id,
                                COALESCE(tops.finished_count, 0) AS finished_count,
                                COALESCE(tops.top_weight, 0) AS top_weight,
                                ((:size - LEAST(:size, COALESCE(tops.finished_count, 0))) ^ 2) / (:size ^ 2) AS unfinished_weight
                            FROM levels
                            LEFT JOIN tops
                            ON tops.level_id = levels.id
                        )

                        SELECT
                            id,
                            finished_count,
                            (top_weight / 120000 * 0.8) + (unfinished_weight  * 0.2) AS track_weight
                        FROM stats
                    )
                ',
                ['size' => $opts['top_size']]
            );
        }

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE weighted_levels AS (
                SELECT
                    workshop_levels.id,
                    workshop_levels.name,
                    workshop_levels.is_sprint,
                    workshop_levels.is_challenge,
                    workshop_levels.is_stunt,
                    sprint_stats.finished_count AS sprint_finished_count,
                    sprint_stats.track_weight AS sprint_track_weight,
                    challenge_stats.finished_count AS challenge_finished_count,
                    challenge_stats.track_weight AS challenge_track_weight,
                    stunt_stats.finished_count AS stunt_finished_count,
                    stunt_stats.track_weight AS stunt_track_weight
                FROM workshop_levels
                LEFT JOIN sprint_stats
                ON sprint_stats.id = workshop_levels.id
                LEFT JOIN challenge_stats
                ON challenge_stats.id = workshop_levels.id
                LEFT JOIN stunt_stats
                ON stunt_stats.id = workshop_levels.id
            )
        ');

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE workshop_points AS (
                SELECT
                    rank,
                    CEIL(1000.0 * (1.0 - POWER((rank - 1.0)/100.0, 0.5))) AS jnvsor_points
                FROM generate_series(1, 100) rank
            )
        ');

        foreach ($this->lb_types as $type => $opts) {
            $this->xdb->executeUpdate('
                CREATE TEMPORARY TABLE weighted_'.$type.'_leaderboard AS (
                    WITH lb AS NOT MATERIALIZED (
                        SELECT * FROM '.$type.'_leaderboard_entries
                    ),
                    levels AS NOT MATERIALIZED (
                        SELECT id, '.$type.'_track_weight AS track_weight
                        FROM weighted_levels
                        WHERE is_'.$type.'
                    )

                    SELECT
                        level_id,
                        steam_id,
                        lb.rank AS rank,
                        lb.'.$opts['score_field'].',
                        COALESCE(points.jnvsor_points, 0) AS workshop_score,
                        COALESCE(points.jnvsor_points, 0) * levels.track_weight AS workshop_score_weighted,
                        ROW_NUMBER() OVER (
                            PARTITION BY steam_id
                            ORDER BY levels.track_weight DESC
                        ) - 1 AS row_number
                    FROM lb
                    INNER JOIN levels
                    ON levels.id = lb.level_id
                    LEFT JOIN workshop_points AS points
                    ON lb.rank = points.rank
                )
            ');

            $this->xdb->executeUpdate('
                CREATE TEMPORARY TABLE '.$type.'_scores AS (
                    WITH lb AS NOT MATERIALIZED (
                        SELECT * FROM weighted_'.$type.'_leaderboard
                    ),
                    levels AS NOT MATERIALIZED (
                        SELECT id
                        FROM weighted_levels
                        WHERE is_'.$type.'
                    )

                    SELECT
                        user_weights.steam_id,
                        COUNT(level_id) AS count,
                        SUM(
                            COALESCE(workshop_score_weighted, 0) * (
                                1.0 - (CAST(total_tracks_weight - POWER(total_tracks - row_number, 2) AS real) / total_tracks_weight)
                            )
                        ) AS score
                    FROM user_weights
                    CROSS JOIN (
                        SELECT
                            COUNT(*) AS total_tracks,
                            POWER(COUNT(*), 2) AS total_tracks_weight
                        FROM levels
                    ) AS diminishing_returns
                    INNER JOIN lb
                    ON lb.steam_id = user_weights.steam_id
                    GROUP BY user_weights.steam_id
                )
            ');
        }

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE user_scores AS (
                SELECT
                    user_weights.steam_id,
                    user_weights.name,
                    user_weights.weight AS holdboost_score,
                    COALESCE(sprint.count, 0) AS sprint_count,
                    COALESCE(sprint.score, 0) AS sprint_score,
                    COALESCE(challenge.count, 0) AS challenge_count,
                    COALESCE(challenge.score, 0) AS challenge_score,
                    COALESCE(stunt.count, 0) AS stunt_count,
                    COALESCE(stunt.score, 0) AS stunt_score
                FROM user_weights
                LEFT JOIN sprint_scores AS sprint
                ON user_weights.steam_id = sprint.steam_id
                LEFT JOIN challenge_scores AS challenge
                ON user_weights.steam_id = challenge.steam_id
                LEFT JOIN stunt_scores AS stunt
                ON user_weights.steam_id = stunt.steam_id
            )
        ');

        $db->transactional(function ($db) {
            $weighted_levels = $this->xdb->executeQuery('
                SELECT
                    id,
                    name,
                    CAST(is_sprint AS integer) AS is_sprint,
                    CAST(is_challenge AS integer) AS is_challenge,
                    CAST(is_stunt AS integer) AS is_stunt,
                    sprint_finished_count,
                    sprint_track_weight,
                    challenge_finished_count,
                    challenge_track_weight,
                    stunt_finished_count,
                    stunt_track_weight
                FROM weighted_levels
            ');
            $db->executeUpdate('DROP TABLE IF EXISTS workshop_levels');
            // *_finished_count fields are cached here for performance purposes
            $db->executeUpdate('
                CREATE TABLE workshop_levels (
                    id integer NOT NULL PRIMARY KEY,
                    name text NOT NULL,
                    is_sprint integer NOT NULL,
                    is_challenge integer NOT NULL,
                    is_stunt integer NOT NULL,
                    sprint_finished_count integer NULL,
                    sprint_track_weight real NULL,
                    challenge_finished_count integer NULL,
                    challenge_track_weight real NULL,
                    stunt_finished_count integer NULL,
                    stunt_track_weight real NULL
                ) WITHOUT ROWID
            ');

            $this->bulkInsert($db, 'workshop_levels', $weighted_levels);

            $user_scores = $this->xdb->executeQuery('SELECT * FROM user_scores');
            $db->executeUpdate('DROP TABLE IF EXISTS users');
            // *_count fields are cached here for performance purposes
            $db->executeUpdate('
                CREATE TABLE IF NOT EXISTS users (
                    steam_id integer NOT NULL PRIMARY KEY,
                    name text NOT NULL,
                    holdboost_score integer NOT NULL,
                    sprint_count integer NOT NULL,
                    sprint_score real NOT NULL,
                    challenge_count integer NOT NULL,
                    challenge_score real NOT NULL,
                    stunt_count integer NOT NULL,
                    stunt_score real NOT NULL
                ) WITHOUT ROWID
            ');
            $this->bulkInsert($db, 'users', $user_scores);

            foreach ($this->lb_types as $type => $opts) {
                $weighted_leaderboard = $this->xdb->executeQuery('
                    SELECT level_id, steam_id, rank, '.$opts['score_field'].', workshop_score, workshop_score_weighted
                    FROM weighted_'.$type.'_leaderboard'
                );

                $db->executeUpdate('DROP TABLE IF EXISTS weighted_'.$type.'_leaderboard');
                $db->executeUpdate('
                    CREATE TABLE IF NOT EXISTS weighted_'.$type.'_leaderboard (
                        level_id integer NOT NULL,
                        steam_id integer NOT NULL,
                        rank integer NOT NULL,
                        '.$opts['score_field'].' integer NOT NULL,
                        workshop_score integer NOT NULL,
                        workshop_score_weighted real NOT NULL,
                        PRIMARY KEY(level_id, steam_id)
                    ) WITHOUT ROWID
                ');
                $db->executeUpdate('
                    CREATE INDEX weighted_'.$type.'_leaderboard_level_id
                    ON weighted_'.$type.'_leaderboard (level_id)
                ');
                $db->executeUpdate('
                    CREATE INDEX weighted_'.$type.'_leaderboard_steam_id
                    ON weighted_'.$type.'_leaderboard (steam_id)
                ');
                $this->bulkInsert($db, 'weighted_'.$type.'_leaderboard', $weighted_leaderboard);
            }
        });

        $diff = microtime(true) - $start;

        echo "Done in ".number_format($diff, 3)." seconds".PHP_EOL;
    }

    private function bulkInsert(Connection $db, string $table, $query)
    {
        try {
            $config = $db->getConfiguration();
            $logger = $config->getSQLLogger();
            $config->setSQLLogger();

            $preamble = 'INSERT INTO '.$table.' VALUES';
            $row_placeholders = '('.implode(',', array_fill(0, $query->columnCount(), '?')).')';
            $batch = [];

            do {
                $row = $query->fetchNumeric();

                if ($row) {
                    $batch[] = $row;
                }

                if ($batch && (!$row || count($batch) >= 1000)) {
                    $placeholders = implode(', ', array_fill(0, count($batch), $row_placeholders));

                    $db->executeUpdate(
                        $preamble.$placeholders,
                        array_merge(...$batch)
                    );

                    $batch = [];
                }
            } while ($row);
        } finally {
            $config->setSQLLogger($logger);
        }
    }
}
