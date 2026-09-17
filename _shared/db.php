<?php
/**
 * BrainPal PostgreSQL / Supabase compatibility layer.
 *
 * Keeps the existing BrainPal PHP endpoints working while using
 * PostgreSQL through PDO. Legacy mysqli-style methods are retained
 * for compatibility with the existing API code.
 */
declare(strict_types=1);

(function (): void {
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) return;

    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || getenv($key) !== false) continue;

        if (
            (strlen($value) >= 2) &&
            (($value[0] === '"' && substr($value, -1) === '"') ||
             ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
    }
})();

if (!class_exists('BrainPalDb')) {
    final class BrainPalDb
    {
        private PDO $pdo;
        public ?string $connect_error = null;
        public string $error = '';
        public int $insert_id = 0;
        public int $affected_rows = 0;

        private const BOOLEAN_COLUMNS = [
            'show_xp','show_streak','show_badges','leaderboard','xp_animation','confetti',
            'is_read','daily_reminder','quiz_reminder','streak_alert','weekly_report',
            'badge_unlocked','level_up','xp_milestones','enable_reminder_time',
            'is_correct','is_verified','leaderboard_visible','diagnostic_completed',
            'ai_generated'
        ];

        /*
         * Identifiers from the Supabase schema that may legitimately be
         * double-quoted in legacy SQL. Any other MySQL-style double quoted
         * token is treated as a string literal.
         */
        private const QUOTED_IDENTIFIERS = ["academic", "academic_id", "academic_year", "account_status", "action", "active_schedule_id", "activity_logs", "activity_logs_ibfk_1", "admin", "admin_id", "age", "ai_generated", "answer_id", "answer_type", "approval_log_id", "approval_status", "archive_id", "archived_at", "attempt_answer_id", "attempt_id", "availability_id", "average_quiz_score", "average_score", "badge_unlocked", "birthdate", "carry_count", "choice_a", "choice_b", "choice_c", "choice_d", "choice_id", "choice_letter", "choice_text", "completed_at", "completed_materials", "completed_quizzes", "confetti", "content_approval_logs", "content_id", "content_type", "correct_answer", "current_streak", "daily_reminder", "date_created", "date_deleted", "date_end", "date_start", "date_taken", "date_updated", "day_name", "description", "detail_id", "diagnostic", "diagnostic_completed", "diagnostic_details", "diagnostic_details_ibfk_1", "diagnostic_details_ibfk_2", "diagnostic_ibfk_1", "diagnostic_ibfk_2", "diagnostic_id", "diagnostic_result", "diagnostic_result_ibfk_1", "diagnostic_result_ibfk_2", "difficulty", "difficulty_level", "duration_minutes", "earned_points", "email", "email_verification_code", "email_verification_expires", "enable_reminder_time", "extension", "firstName", "fk_attempt_answers_attempt", "fk_attempt_answers_question", "fk_cal_reviewed_by", "fk_cal_submitted_by", "fk_details_question", "fk_details_result", "fk_diagnostic_academic", "fk_diagnostic_student", "fk_lm_reviewed_by", "fk_material_academic", "fk_pending_student", "fk_pending_subject", "fk_quiz_admin", "fk_quiz_answers_question", "fk_quiz_choices_question", "fk_quiz_questions_quiz", "fk_quiz_reviewed_by", "fk_result_academic", "fk_schedule_academic", "fk_student_academic", "fk_student_strand", "fk_study_session_material", "fk_study_session_schedule", "fk_study_session_student", "fk_summary_academic", "fk_summary_student", "fk_summary_subject", "fk_weekly_submission_report", "fullname", "gamification_preferences", "gender", "grade_level", "hashed_password", "idx_admin_email_status", "idx_admin_recovery", "idx_archive_student_week", "idx_availability_student", "idx_cal_content", "idx_learning_material_schedule_active", "idx_lm_admin", "idx_lm_approval", "idx_lm_reviewer", "idx_notifications_reference", "idx_notifications_type_reference", "idx_notifications_unread", "idx_notifications_user", "idx_notifications_user_created", "idx_pending_student", "idx_pending_week", "idx_quiz_admin", "idx_quiz_approval", "idx_quiz_attempt_student_date", "idx_quiz_reviewer", "idx_student_email_status", "idx_student_recovery", "idx_study_heartbeat", "idx_study_material", "idx_study_schedule_student_subject_day", "idx_study_student_status", "idx_weekly_report_status", "idx_weekly_report_week", "idx_weekly_submission_status", "improvement_rate", "is_correct", "is_read", "is_verified", "lastName", "last_calculated", "last_heartbeat_at", "last_missed_week_start", "last_open_date", "last_update", "leaderboard", "leaderboard_visible", "learning_material", "learning_material_ibfk_1", "learning_material_ibfk_2", "level_classification", "level_up", "link", "log_id", "longest_streak", "m_initial", "material_id", "message", "notification_id", "notification_preferences", "notifications", "original_date_created", "original_schedule_id", "otp_expiry", "pending_id", "pending_subjects", "points", "points_awarded", "points_earned", "preference_id", "profile_photo", "progress_summary", "question", "question_id", "question_number", "question_text", "question_type", "quiz", "quiz_answers", "quiz_attempt", "quiz_attempt_answers", "quiz_attempts", "quiz_choices", "quiz_id", "quiz_questions", "quiz_reminder", "quiz_title", "quiz_type", "ranking", "reason", "reference_id", "rejection_reason", "reminder_time", "report_status", "reset_otp", "reset_token", "result_id", "review_notes", "reviewed_at", "reviewed_by", "role", "schedule_id", "scheduled_day", "scheduled_sessions", "score", "semester", "session_id", "show_badges", "show_streak", "show_xp", "slot_end", "slot_start", "specialization", "specialization_id", "specialization_name", "start_time", "started_at", "status", "strand", "strand_id", "strand_name", "streak_alert", "student", "studentNo", "student_answer", "student_id", "student_weekly_availability", "study_material_sessions", "study_schedule", "study_schedule_archive", "study_schedule_pending", "subject", "subject_id", "subject_name", "submission_id", "submission_status", "submitted_at", "submitted_by", "submitted_role", "summary_id", "title", "token_expiry", "total_attempts", "total_opens", "total_points", "total_questions", "total_score", "total_seconds", "total_study_seconds", "type", "uq_archive_original_schedule", "uq_gamification_preferences_user", "uq_learning_material_active_schedule", "uq_learning_material_schedule", "uq_notification_preferences_user", "uq_pending_student_subject", "uq_student_day_slot", "uq_study_student_material", "uq_weekly_report_admin", "uq_weekly_student", "user_answer", "user_id", "user_role", "week_end", "week_start", "weekly_report", "weekly_report_id", "weekly_report_submissions", "weekly_student_reports", "xp_animation", "xp_milestones"];

        public function __construct()
        {
            $host = getenv('SUPABASE_DB_HOST') ?: '';
            $port = getenv('SUPABASE_DB_PORT') ?: '5432';
            $name = getenv('SUPABASE_DB_NAME') ?: 'postgres';
            $user = getenv('SUPABASE_DB_USER') ?: '';
            $pass = getenv('SUPABASE_DB_PASSWORD') ?: '';
            $sslmode = getenv('SUPABASE_DB_SSLMODE') ?: 'require';

            if ($host === '' || $user === '' || $pass === '') {
                throw new RuntimeException(
                    'Supabase database environment variables are not configured.'
                );
            }

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                $host, $port, $name, $sslmode
            );

            try {
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => true,
                ]);
                $this->pdo->exec("SET TIME ZONE 'UTC'");
            } catch (Throwable $e) {
                $this->connect_error = $e->getMessage();
                throw $e;
            }
        }

        public function prepare(string $sql): BrainPalStmt|false
        {
            try {
                return new BrainPalStmt($this, $this->pdo->prepare(self::normalizeSql($sql)), $sql);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }

        public function query(string $sql): BrainPalResult|bool
        {
            try {
                $normalized = self::normalizeSql($sql);
                $stmt = $this->pdo->query($normalized);
                $this->affected_rows = $stmt->rowCount();

                if (preg_match('/^\s*(SELECT|WITH|SHOW|EXPLAIN)\b/i', $normalized)) {
                    return new BrainPalResult($stmt);
                }
                return true;
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }

        public function begin_transaction(): bool
        {
            try { return $this->pdo->beginTransaction(); }
            catch (Throwable $e) { $this->error = $e->getMessage(); return false; }
        }

        public function commit(): bool
        {
            try { return $this->pdo->commit(); }
            catch (Throwable $e) { $this->error = $e->getMessage(); return false; }
        }

        public function rollback(): bool
        {
            try {
                if (!$this->pdo->inTransaction()) return true;
                return $this->pdo->rollBack();
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }

        public function set_charset(string $charset): bool { return true; }

        public function real_escape_string(string $value): string
        {
            return str_replace("'", "''", $value);
        }

        public function close(): void {}

        public function rawPdo(): PDO { return $this->pdo; }

        public static function normalizeSql(string $sql): string
        {
            /*
             * MySQL identifier quoting.
             */
            $sql = str_replace('`', '"', $sql);

            /*
             * MySQL commonly allows double-quoted string literals.
             * Preserve only identifiers that are actually in the schema;
             * convert all other double-quoted tokens to PostgreSQL strings.
             */
            $sql = preg_replace_callback(
                '/"([^"]*)"/',
                static function (array $m): string {
                    $token = $m[1];
                    if (in_array($token, self::QUOTED_IDENTIFIERS, true)) {
                        return '"' . $token . '"';
                    }
                    return "'" . str_replace("'", "''", $token) . "'";
                },
                $sql
            ) ?? $sql;

            /*
             * Protect mixed/camel-case schema columns.
             */
            foreach (['firstName','lastName','studentNo'] as $identifier) {
                $sql = preg_replace(
                    '/(?<!["A-Za-z0-9_])' . preg_quote($identifier, '/') . '(?!["A-Za-z0-9_])/u',
                    '"' . $identifier . '"',
                    $sql
                ) ?? $sql;
            }

            $sql = preg_replace('/\bNOW\(\)/i', 'CURRENT_TIMESTAMP', $sql) ?? $sql;
            $sql = preg_replace('/\bCURDATE\(\)/i', 'CURRENT_DATE', $sql) ?? $sql;
            $sql = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $sql) ?? $sql;

            /*
             * TIMESTAMPDIFF(unit, start, end) -> EXTRACT(EPOCH ...).
             */
            $sql = preg_replace_callback(
                '/TIMESTAMPDIFF\s*\(\s*(SECOND|MINUTE|HOUR|DAY)\s*,\s*([^,]+)\s*,\s*([^)]+)\)/i',
                static function (array $m): string {
                    $unit = strtoupper($m[1]);
                    $start = trim($m[2]);
                    $end = trim($m[3]);

                    return match ($unit) {
                        'SECOND' => "FLOOR(EXTRACT(EPOCH FROM (($end) - ($start)))::numeric)",
                        'MINUTE' => "FLOOR(EXTRACT(EPOCH FROM (($end) - ($start))) / 60)::numeric",
                        'HOUR'   => "FLOOR(EXTRACT(EPOCH FROM (($end) - ($start))) / 3600)::numeric",
                        'DAY'    => "FLOOR(EXTRACT(EPOCH FROM (($end) - ($start))) / 86400)::numeric",
                    };
                },
                $sql
            ) ?? $sql;

            $sql = preg_replace_callback(
                '/\b(CURRENT_DATE|CURRENT_TIMESTAMP)\s*-\s*INTERVAL\s+(\d+)\s+(DAY|SECOND|MINUTE|HOUR|WEEK)\b/i',
                static fn(array $m): string =>
                    $m[1] . " - INTERVAL '" . $m[2] . ' ' . strtolower($m[3]) . "'",
                $sql
            ) ?? $sql;

            $sql = preg_replace_callback(
                '/DATE_SUB\s*\(\s*(CURRENT_TIMESTAMP|CURRENT_DATE)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|SECOND|MINUTE|HOUR|WEEK)\s*\)/i',
                static fn(array $m): string =>
                    $m[1] . " - INTERVAL '" . $m[2] . ' ' . strtolower($m[3]) . "'",
                $sql
            ) ?? $sql;

            $sql = preg_replace_callback(
                '/DATE_ADD\s*\(\s*([^,()]+|\([^)]*\))\s*,\s*INTERVAL\s+(\d+)\s+(DAY|SECOND|MINUTE|HOUR|WEEK)\s*\)/i',
                static fn(array $m): string =>
                    '(' . trim($m[1]) . " + INTERVAL '" . $m[2] . ' ' . strtolower($m[3]) . "')",
                $sql
            ) ?? $sql;

            $sql = preg_replace(
                '/\bWEEKDAY\s*\(\s*CURRENT_DATE\s*\)/i',
                '(EXTRACT(ISODOW FROM CURRENT_DATE)::int - 1)',
                $sql
            ) ?? $sql;

            $sql = preg_replace_callback(
                '/DATE_FORMAT\s*\(\s*([^,]+)\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
                static function (array $m): string {
                    $map = [
                        '%M %e, %Y' => 'FMMonth FMDD, YYYY',
                        '%Y-%m-%d' => 'YYYY-MM-DD',
                        '%h:%i %p' => 'HH12:MI AM',
                        '%H:%i' => 'HH24:MI',
                    ];
                    $fmt = $map[$m[2]] ?? $m[2];
                    return "TO_CHAR(" . trim($m[1]) . ", '" . str_replace("'", "''", $fmt) . "')";
                },
                $sql
            ) ?? $sql;

            $sql = preg_replace_callback(
                '/TIME_FORMAT\s*\(\s*([^,]+)\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
                static function (array $m): string {
                    $map = [
                        '%h:%i %p' => 'HH12:MI AM',
                        '%H:%i' => 'HH24:MI',
                    ];
                    $fmt = $map[$m[2]] ?? $m[2];
                    return "TO_CHAR(" . trim($m[1]) . ", '" . str_replace("'", "''", $fmt) . "')";
                },
                $sql
            ) ?? $sql;

            $sql = preg_replace_callback(
                '/GROUP_CONCAT\s*\(\s*DISTINCT\s+(.+?)\s+ORDER BY\s+(.+?)\s+SEPARATOR\s+[\'"]([^\'"]*)[\'"]\s*\)/is',
                static fn(array $m): string =>
                    "STRING_AGG(DISTINCT {$m[1]}, '" . str_replace("'", "''", $m[3]) . "' ORDER BY {$m[2]})",
                $sql
            ) ?? $sql;

            $sql = preg_replace_callback(
                '/GROUP_CONCAT\s*\(\s*(.+?)\s+SEPARATOR\s+[\'"]([^\'"]*)[\'"]\s*\)/is',
                static fn(array $m): string =>
                    "STRING_AGG({$m[1]}, '" . str_replace("'", "''", $m[2]) . "')",
                $sql
            ) ?? $sql;

            /*
             * Convert simple MySQL IF(condition, true, false) SQL expressions.
             * Repeatedly handles nested/simple functions without touching PHP code.
             */
            for ($i = 0; $i < 5; $i++) {
                $before = $sql;
                $sql = preg_replace_callback(
                    '/\bIF\s*\(\s*([^(),]+(?:\([^()]*\)[^(),]*)?)\s*,\s*([^(),]+)\s*,\s*([^()]*(?:\([^()]*\)[^()]*)?)\)/i',
                    static fn(array $m): string =>
                        '(CASE WHEN ' . trim($m[1]) . ' THEN ' . trim($m[2]) . ' ELSE ' . trim($m[3]) . ' END)',
                    $sql
                ) ?? $sql;
                if ($sql === $before) break;
            }

            /*
             * INSERT IGNORE.
             */
            if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO\b/i', $sql)) {
                $sql = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO\b/i', 'INSERT INTO', $sql, 1) ?? $sql;
                if (!preg_match('/\bON\s+CONFLICT\b/i', $sql)) {
                    $sql = rtrim($sql, " \t\r\n;") . ' ON CONFLICT DO NOTHING';
                }
            }

            /*
             * MySQL ON DUPLICATE KEY UPDATE -> PostgreSQL ON CONFLICT.
             */
            if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
                $sql = preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', 'ON CONFLICT DO UPDATE SET', $sql, 1) ?? $sql;
                $sql = preg_replace('/\bVALUES\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/i', 'EXCLUDED.$1', $sql) ?? $sql;

                $targets = [
                    'gamification_preferences' => '(user_id, user_role)',
                    'notification_preferences' => '(user_id, user_role)',
                    'study_schedule_pending' => '(student_id, subject_id)',
                    'weekly_student_reports' => '(student_id, week_start)',
                    'weekly_report_submissions' => '(weekly_report_id, submitted_by)',
                    'study_material_sessions' => '(student_id, material_id)',
                ];

                foreach ($targets as $table => $target) {
                    if (preg_match('/INSERT\s+INTO\s+' . preg_quote($table, '/') . '\b/i', $sql)) {
                        $sql = preg_replace(
                            '/ON\s+CONFLICT\s+DO\s+UPDATE\s+SET/i',
                            'ON CONFLICT ' . $target . ' DO UPDATE SET',
                            $sql,
                            1
                        ) ?? $sql;
                        break;
                    }
                }
            }

            /*
             * Runtime CREATE TABLE written for MySQL.
             */
            if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', $sql)) {
                $sql = preg_replace('/\bINT\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i', 'integer GENERATED BY DEFAULT AS IDENTITY NOT NULL', $sql) ?? $sql;
                $sql = preg_replace('/\bINT\s+AUTO_INCREMENT\b/i', 'integer GENERATED BY DEFAULT AS IDENTITY', $sql) ?? $sql;
                $sql = preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i', 'boolean', $sql) ?? $sql;
                $sql = preg_replace_callback('/ENUM\s*\((.*?)\)/is', static fn() => 'varchar(50)', $sql) ?? $sql;
                $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $sql) ?? $sql;
                $sql = preg_replace('/\bENGINE\s*=\s*\w+/i', '', $sql) ?? $sql;
                $sql = preg_replace('/\bDEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql) ?? $sql;
                $sql = preg_replace('/\bCOLLATE\s*=\s*[\w_]+/i', '', $sql) ?? $sql;
                $sql = preg_replace('/,\s*UNIQUE\s+KEY\s+(\w+)\s*\(([^)]+)\)/i', ', UNIQUE ($2)', $sql) ?? $sql;
                $sql = preg_replace('/,\s*KEY\s+\w+\s*\([^)]+\)/i', '', $sql) ?? $sql;
            }

            foreach (self::BOOLEAN_COLUMNS as $boolCol) {
                $sql = preg_replace(
                    '/\b' . preg_quote($boolCol, '/') . '\s*=\s*0\b/i',
                    $boolCol . ' = FALSE',
                    $sql
                ) ?? $sql;
                $sql = preg_replace(
                    '/\b' . preg_quote($boolCol, '/') . '\s*=\s*1\b/i',
                    $boolCol . ' = TRUE',
                    $sql
                ) ?? $sql;
            }

            return $sql;
        }

        /**
         * Return parameter indexes that belong to PostgreSQL BOOLEAN columns.
         * This is needed because legacy mysqli bind_param() often labels 0/1
         * values as integer even when the migrated PostgreSQL column is boolean.
         */
        public static function booleanParameterPositions(string $sql): array
        {
            $positions = [];

            if (preg_match('/INSERT\s+INTO\s+[A-Za-z0-9_\." ]+\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/is', $sql, $m)) {
                $cols = self::splitCsv($m[1]);
                $vals = self::splitCsv($m[2]);
                $paramIndex = 0;
                foreach ($vals as $i => $v) {
                    if (trim($v) !== '?') continue;
                    $col = trim($cols[$i] ?? '', "\\\"` \\t\\r\\n");
                    if (in_array($col, self::BOOLEAN_COLUMNS, true)) {
                        $positions[$paramIndex] = true;
                    }
                    $paramIndex++;
                }
            }

            if (preg_match('/\\bSET\\b(.*?)(?:\\bWHERE\\b|$)/is', $sql, $m)) {
                foreach (self::splitCsv($m[1]) as $assignment) {
                    if (preg_match('/\\b([A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*\\?/i', $assignment, $mm)) {
                        $col = trim($mm[1], "\\\"` \\t\\r\\n");
                        if (!in_array($col, self::BOOLEAN_COLUMNS, true)) continue;
                        $pos = substr_count(substr($sql, 0, strpos($sql, $assignment)), '?');
                        $positions[$pos] = true;
                    }
                }
            }

            foreach (self::BOOLEAN_COLUMNS as $col) {
                if (preg_match_all('/\\b' . preg_quote($col, '/') . '\\s*=\\s*\\?/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $positions[substr_count(substr($sql, 0, $match[1]), '?')] = true;
                    }
                }
            }

            return $positions;
        }

        public static function normalizeBoundValues(string $sql, array $values): array
        {
            $flags = array_fill(0, count($values), false);

            if (preg_match('/INSERT\s+INTO\s+[A-Za-z0-9_." ]+\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/is', $sql, $m)) {
                $cols = self::splitCsv($m[1]);
                $vals = self::splitCsv($m[2]);
                $paramIndex = 0;

                foreach ($vals as $i => $v) {
                    $col = trim($cols[$i] ?? '', "\"` \t\r\n");
                    if (trim($v) === '?' &&
                        in_array($col, self::BOOLEAN_COLUMNS, true) &&
                        isset($flags[$paramIndex])) {
                        $flags[$paramIndex] = true;
                    }
                    if (trim($v) !== '?') {
                        continue;
                    }
                    $paramIndex++;
                }
            }

            foreach (self::BOOLEAN_COLUMNS as $col) {
                if (preg_match_all('/\b' . preg_quote($col, '/') . '\s*=\s*\?/i', $sql, $ms, PREG_OFFSET_CAPTURE)) {
                    foreach ($ms[0] as $match) {
                        $pos = substr_count(substr($sql, 0, $match[1]), '?');
                        if (isset($flags[$pos])) $flags[$pos] = true;
                    }
                }
            }

            foreach ($flags as $i => $isBool) {
                if ($isBool && array_key_exists($i, $values)) {
                    $values[$i] = (bool)$values[$i];
                }
            }

            return $values;
        }

        private static function splitCsv(string $text): array
        {
            $parts = [];
            $buf = '';
            $depth = 0;
            $quote = null;
            $len = strlen($text);

            for ($i = 0; $i < $len; $i++) {
                $ch = $text[$i];

                if ($quote !== null) {
                    $buf .= $ch;
                    if ($ch === $quote && ($i === 0 || $text[$i - 1] !== '\\')) $quote = null;
                    continue;
                }

                if ($ch === "'" || $ch === '"') {
                    $quote = $ch;
                    $buf .= $ch;
                    continue;
                }

                if ($ch === '(') $depth++;
                elseif ($ch === ')') $depth--;

                if ($ch === ',' && $depth === 0) {
                    $parts[] = trim($buf);
                    $buf = '';
                } else {
                    $buf .= $ch;
                }
            }

            if (trim($buf) !== '') $parts[] = trim($buf);
            return $parts;
        }
    }

    final class BrainPalStmt
    {
        private BrainPalDb $db;
        private PDOStatement $stmt;
        private string $originalSql;
        private array $params = [];
        private string $types = '';
        public string $error = '';
        public int $affected_rows = 0;
        public int $insert_id = 0;
        private array $boundResultVars = [];

        public function __construct(BrainPalDb $db, PDOStatement $stmt, string $originalSql)
        {
            $this->db = $db;
            $this->stmt = $stmt;
            $this->originalSql = $originalSql;
        }

        public function bind_param(string $types, &...$vars): bool
        {
            $this->types = $types;
            $this->params = [];
            foreach ($vars as &$var) $this->params[] = &$var;
            return true;
        }

        public function execute(): bool
        {
            try {
                $values = [];
                foreach ($this->params as $v) $values[] = $v;
                $values = BrainPalDb::normalizeBoundValues($this->originalSql, $values);

                $booleanPositions = BrainPalDb::booleanParameterPositions($this->originalSql);

                foreach ($values as $i => $value) {
                    $type = $this->types[$i] ?? '';
                    if (isset($booleanPositions[$i])) {
                        $value = (bool)$value;
                        $pdoType = PDO::PARAM_BOOL;
                    } else {
                        $pdoType = match ($type) {
                            'i' => PDO::PARAM_INT,
                            'b' => PDO::PARAM_BOOL,
                            default => is_bool($value) ? PDO::PARAM_BOOL : PDO::PARAM_STR,
                        };
                    }
                    $this->stmt->bindValue($i + 1, $value, $pdoType);
                }

                $ok = $this->stmt->execute();
                $this->affected_rows = $this->stmt->rowCount();
                $this->db->affected_rows = $this->affected_rows;

                if ($ok && preg_match('/^\s*INSERT\b/i', BrainPalDb::normalizeSql($this->originalSql))) {
                    try {
                        $q = $this->db->rawPdo()->query('SELECT LASTVAL()');
                        $this->db->insert_id = (int)$q->fetchColumn();
                        $this->insert_id = $this->db->insert_id;
                    } catch (Throwable) {
                        $this->db->insert_id = 0;
                        $this->insert_id = 0;
                    }
                }

                return $ok;
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                $this->db->error = $this->error;
                return false;
            }
        }

        public function get_result(): BrainPalResult
        {
            return new BrainPalResult($this->stmt);
        }

        public function bind_result(&...$vars): bool
        {
            $this->boundResultVars = [];
            foreach ($vars as &$var) $this->boundResultVars[] = &$var;
            return true;
        }

        public function fetch(): array|false
        {
            $row = $this->stmt->fetch(PDO::FETCH_NUM);
            if ($row === false) return false;
            foreach ($this->boundResultVars as $i => &$var) {
                $var = $row[$i] ?? null;
            }
            return $row;
        }

        public function close(): void
        {
            $this->stmt->closeCursor();
        }
    }

    final class BrainPalResult
    {
        private array $rows = [];
        private int $index = 0;
        public int $num_rows = 0;

        public function __construct(PDOStatement $stmt)
        {
            $this->rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->num_rows = count($this->rows);
        }

        public function fetch_assoc(): array|false
        {
            if ($this->index >= count($this->rows)) return false;
            return $this->normalizeRow($this->rows[$this->index++]);
        }

        public function fetch(): array|false { return $this->fetch_assoc(); }

        private function normalizeRow(array $row): array
        {
            foreach ($row as $k => $v) {
                if ($v === 't') $row[$k] = true;
                elseif ($v === 'f') $row[$k] = false;
            }
            return $row;
        }

        public function free(): void
        {
            $this->rows = [];
            $this->index = 0;
        }
    }

    function brainpal_db(): BrainPalDb
    {
        static $connection = null;
        if ($connection instanceof BrainPalDb) return $connection;

        try {
            return $connection = new BrainPalDb();
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'status' => 'error',
                'success' => false,
                'message' => 'Database connection failed.',
                'debug' => $e->getMessage(),
            ]);
            exit;
        }
    }

    function brainpal_cors(string $methods = 'GET, POST, OPTIONS'): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = [
            'http://localhost:8100',
            'http://127.0.0.1:8100',
        ];

        if ($origin === '' || in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        }

        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
        header('Access-Control-Allow-Methods: ' . $methods);
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=UTF-8');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    function brainpal_json_input(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
