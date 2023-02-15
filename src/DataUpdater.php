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
            CREATE TEMPORARY TABLE holdboost_points AS (
              SELECT
                rank,
                ROUND(1000.0 * (1.0 - |/(1.0 - (((rank - 1.0)/1000.0) - 1.0)^2))) AS noodle_points
              FROM generate_series(1, 1000) rank
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

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE user_weights AS (
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
            CREATE TEMPORARY TABLE workshop_tops AS (
              SELECT
                sprint_leaderboard_entries.level_id,
                totals.c AS finished_count,
                (AVG(COALESCE(user_weights.weight, 0)) * 0.75) + (SUM(COALESCE(user_weights.weight, 0)) / 30 * 0.25) AS top_weight
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
            )
        ');

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE workshop_weights AS (
              SELECT
                id,
                name,
                finished_count,
                top_weight,
                unfinished_weight,
                (top_weight / 120000 * 0.8) + (unfinished_weight  * 0.2) AS track_weight
              FROM (
                SELECT
                  workshop_levels.id,
                  workshop_levels.name,
                  COALESCE(workshop_tops.finished_count, 0) AS finished_count,
                  COALESCE(workshop_tops.top_weight, 0) AS top_weight,
                  ((30 - LEAST(30, COALESCE(workshop_tops.finished_count, 0))) ^ 2) / 900 AS unfinished_weight
                FROM workshop_levels
                LEFT JOIN workshop_tops
                ON workshop_tops.level_id = workshop_levels.id
                WHERE workshop_levels.is_sprint
              ) t
            )
        ');

        $this->xdb->executeUpdate('
            CREATE TEMPORARY TABLE weighted_leaderboard AS (
              SELECT
                level_id,
                steam_id,
                sprint_leaderboard_entries.rank AS rank,
                sprint_leaderboard_entries.time AS time,
                COALESCE(points.jnvsor_points, 0) AS workshop_score,
                COALESCE(points.jnvsor_points, 0) * track_weight AS workshop_score_weighted,
                ROW_NUMBER() OVER (
                  PARTITION BY steam_id
                  ORDER BY workshop_weights.track_weight DESC
                ) - 1 AS row_number
              FROM sprint_leaderboard_entries
              INNER JOIN workshop_weights
              ON workshop_weights.id = sprint_leaderboard_entries.level_id
              LEFT JOIN workshop_points AS points
              ON sprint_leaderboard_entries.rank = points.rank
              WHERE sprint_leaderboard_entries.level_id IN(
                SELECT id FROM workshop_levels WHERE is_sprint
              )
            )
        ');

        $db->transactional(function ($db) {
            $user_weights = $this->xdb->executeQuery('SELECT * FROM user_weights');
            $db->executeUpdate('DROP TABLE IF EXISTS user_weights');
            $db->executeUpdate('
                CREATE TABLE user_weights (
                    steam_id integer NOT NULL PRIMARY KEY,
                    name text NOT NULL,
                    weight real NOT NULL
                ) WITHOUT ROWID
            ');
            $this->bulkInsert($db, 'user_weights', $user_weights);

            $workshop_weights = $this->xdb->executeQuery('SELECT * FROM workshop_weights');
            $db->executeUpdate('DROP TABLE IF EXISTS workshop_weights');
            $db->executeUpdate('
                CREATE TABLE workshop_weights (
                    id integer NOT NULL PRIMARY KEY,
                    name text NOT NULL,
                    finished_count integer NOT NULL,
                    top_weight real NOT NULL,
                    unfinished_weight real NOT NULL,
                    track_weight real NOT NULL
                ) WITHOUT ROWID
            ');
            $this->bulkInsert($db, 'workshop_weights', $workshop_weights);

            $weighted_leaderboard = $this->xdb->executeQuery('SELECT * FROM weighted_leaderboard');
            $db->executeUpdate('DROP TABLE IF EXISTS weighted_leaderboard');
            $db->executeUpdate('
                CREATE TABLE IF NOT EXISTS weighted_leaderboard (
                    level_id integer NOT NULL,
                    steam_id integer NOT NULL,
                    rank integer NOT NULL,
                    time integer NOT NULL,
                    workshop_score integer NOT NULL,
                    workshop_score_weighted real NOT NULL,
                    row_number integer NOT NULL,
                    PRIMARY KEY(level_id, steam_id)
                ) WITHOUT ROWID
            ');
            $db->executeUpdate('
                CREATE INDEX weighted_leaderboard_level_id
                ON weighted_leaderboard (level_id)
            ');
            $db->executeUpdate('
                CREATE INDEX weighted_leaderboard_steam_id
                ON weighted_leaderboard (steam_id)
            ');
            $this->bulkInsert($db, 'weighted_leaderboard', $weighted_leaderboard);

            $db->executeUpdate('DROP TABLE IF EXISTS user_scores');
            $db->executeUpdate('
                CREATE TABLE IF NOT EXISTS user_scores (
                    steam_id integer NOT NULL PRIMARY KEY,
                    name text NOT NULL,
                    workshop_count integer NOT NULL,
                    workshop_score integer NOT NULL,
                    workshop_score_weighted real NOT NULL,
                    workshop_score_final real NOT NULL
                ) WITHOUT ROWID
            ');
            $db->getWrappedConnection()->getNativeConnection()->createFunction(
                'power',
                'pow',
                2,
                SQLITE3_DETERMINISTIC
            );
            $db->executeUpdate('
                INSERT INTO user_scores
                SELECT
                  user_weights.steam_id,
                  user_weights.name,
                  COUNT(weighted_leaderboard.level_id) workshop_count,
                  SUM(COALESCE(weighted_leaderboard.workshop_score, 0)) workshop_score,
                  SUM(COALESCE(weighted_leaderboard.workshop_score_weighted, 0)) workshop_score_weighted,
                  SUM(
                    COALESCE(weighted_leaderboard.workshop_score_weighted, 0) * (
                      1.0 - (CAST(total_tracks_weight - POWER(total_tracks - weighted_leaderboard.row_number, 2) AS real) / total_tracks_weight)
                    )
                  ) AS workshop_score_final
                FROM user_weights
                CROSS JOIN (
                  SELECT COUNT(*) AS total_tracks, POWER(COUNT(*), 2) AS total_tracks_weight FROM workshop_weights
                ) AS workshop_diminishing_returns
                INNER JOIN weighted_leaderboard
                ON weighted_leaderboard.steam_id = user_weights.steam_id
                GROUP BY user_weights.steam_id
            ');
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
