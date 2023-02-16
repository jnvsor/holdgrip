<?php

namespace DHB;

use Doctrine\DBAL\Connection;

class DataUpdater
{
    private $xdb;

    public function __construct(Connection $external_db)
    {
        $this->xdb = $external_db;
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

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE weighed_levels AS (
                WITH sprint_tops AS (
                    SELECT
                        sprint_leaderboard_entries.level_id,
                        totals.c AS finished_count,
                        (AVG(COALESCE(user_weights.weight, 0)) * 0.75) +
                            (SUM(COALESCE(user_weights.weight, 0)) / GREATEST(COUNT(*), 30) * 0.25) AS top_weight
                    FROM sprint_leaderboard_entries
                    INNER JOIN (
                        SELECT level_id, COUNT(*) c
                        FROM sprint_leaderboard_entries
                        GROUP BY sprint_leaderboard_entries.level_id
                    ) totals
                    ON totals.level_id = sprint_leaderboard_entries.level_id
                    LEFT JOIN user_weights
                    ON sprint_leaderboard_entries.steam_id = user_weights.steam_id
                    WHERE sprint_leaderboard_entries.level_id IN(
                        SELECT id FROM workshop_levels WHERE is_sprint
                    )
                    AND sprint_leaderboard_entries.rank < 31
                    GROUP BY sprint_leaderboard_entries.level_id, totals.c
                ),
                sprint_stats AS (
                    SELECT
                        workshop_levels.id,
                        sprint_tops.finished_count,
                        COALESCE(sprint_tops.top_weight, 0) AS top_weight,
                        ((30 - LEAST(30, COALESCE(sprint_tops.finished_count, 0))) ^ 2) / 900 AS unfinished_weight
                    FROM workshop_levels
                    LEFT JOIN sprint_tops
                    ON sprint_tops.level_id = workshop_levels.id
                    WHERE workshop_levels.is_sprint
                )

                SELECT
                    workshop_levels.id,
                    workshop_levels.name,
                    workshop_levels.is_sprint,
                    workshop_levels.is_challenge,
                    workshop_levels.is_stunt,
                    sprint_stats.finished_count AS sprint_finished_count,
                    (sprint_stats.top_weight / 120000 * 0.8) + (sprint_stats.unfinished_weight  * 0.2) AS sprint_track_weight
                FROM workshop_levels
                LEFT JOIN sprint_stats
                ON sprint_stats.id = workshop_levels.id
            )
        ');

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE weighted_sprint_leaderboard AS (
                WITH workshop_points AS MATERIALIZED (
                    SELECT
                        rank,
                        CEIL(1000.0 * (1.0 - POWER((rank - 1.0)/100.0, 0.5))) AS jnvsor_points
                    FROM generate_series(1, 100) rank
                )

                SELECT
                    level_id,
                    steam_id,
                    sprint_leaderboard_entries.rank AS rank,
                    sprint_leaderboard_entries.time AS time,
                    COALESCE(points.jnvsor_points, 0) AS workshop_score,
                    COALESCE(points.jnvsor_points, 0) * weighed_levels.sprint_track_weight AS workshop_score_weighted,
                    ROW_NUMBER() OVER (
                        PARTITION BY steam_id
                        ORDER BY weighed_levels.sprint_track_weight DESC
                    ) - 1 AS row_number
                FROM sprint_leaderboard_entries
                INNER JOIN weighed_levels
                ON weighed_levels.id = sprint_leaderboard_entries.level_id
                LEFT JOIN workshop_points AS points
                ON sprint_leaderboard_entries.rank = points.rank
                WHERE weighed_levels.is_sprint
            )
        ');

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE user_scores AS (
                WITH scores AS (
                    SELECT
                        user_weights.steam_id,
                        COUNT(weighted_sprint_leaderboard.level_id) AS sprint_count,
                        SUM(
                            COALESCE(weighted_sprint_leaderboard.workshop_score_weighted, 0) * (
                                1.0 - (CAST(total_tracks_weight - POWER(total_tracks - row_number, 2) AS real) / total_tracks_weight)
                            )
                        ) AS sprint_score,
                        0 AS challenge_count,
                        0 AS challenge_score,
                        0 AS stunt_count,
                        0 AS stunt_score
                    FROM user_weights
                    CROSS JOIN (
                        SELECT
                            COUNT(*) AS total_tracks,
                            POWER(COUNT(*), 2) AS total_tracks_weight
                        FROM weighed_levels
                        WHERE is_sprint
                    ) AS workshop_diminishing_returns
                    INNER JOIN weighted_sprint_leaderboard
                    ON weighted_sprint_leaderboard.steam_id = user_weights.steam_id
                    GROUP BY user_weights.steam_id
                )

                SELECT
                    user_weights.steam_id,
                    user_weights.name,
                    user_weights.weight AS holdboost_score,
                    COALESCE(sprint_count, 0) AS sprint_count,
                    COALESCE(sprint_score, 0) AS sprint_score,
                    COALESCE(challenge_count, 0) AS challenge_count,
                    COALESCE(challenge_score, 0) AS challenge_score,
                    COALESCE(stunt_count, 0) AS stunt_count,
                    COALESCE(stunt_score, 0) AS stunt_score
                FROM user_weights
                LEFT JOIN scores
                ON user_weights.steam_id = scores.steam_id
            )
        ');

        $db->transactional(function ($db) {
            $weighed_levels = $this->xdb->executeQuery('SELECT * FROM weighed_levels');
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
                    sprint_track_weight real NULL
                ) WITHOUT ROWID
            ');
            $this->bulkInsert($db, 'workshop_levels', $weighed_levels);

            $weighted_sprint_leaderboard = $this->xdb->executeQuery('
                SELECT level_id, steam_id, rank, time, workshop_score, workshop_score_weighted
                FROM weighted_sprint_leaderboard
            ');
            $db->executeUpdate('DROP TABLE IF EXISTS weighted_sprint_leaderboard');
            $db->executeUpdate('
                CREATE TABLE IF NOT EXISTS weighted_sprint_leaderboard (
                    level_id integer NOT NULL,
                    steam_id integer NOT NULL,
                    rank integer NOT NULL,
                    time integer NOT NULL,
                    workshop_score integer NOT NULL,
                    workshop_score_weighted real NOT NULL,
                    PRIMARY KEY(level_id, steam_id)
                ) WITHOUT ROWID
            ');
            $db->executeUpdate('
                CREATE INDEX weighted_leaderboard_level_id
                ON weighted_sprint_leaderboard (level_id)
            ');
            $db->executeUpdate('
                CREATE INDEX weighted_leaderboard_steam_id
                ON weighted_sprint_leaderboard (steam_id)
            ');
            $this->bulkInsert($db, 'weighted_sprint_leaderboard', $weighted_sprint_leaderboard);

            $user_scores = $this->xdb->executeQuery('SELECT * FROM user_scores');
            $db->executeUpdate('DROP TABLE IF EXISTS user_scores');
            // *_count fields are cached here for performance purposes
            $db->executeUpdate('
                CREATE TABLE IF NOT EXISTS user_scores (
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
            $this->bulkInsert($db, 'user_scores', $user_scores);
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
