<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Discovery;

/**
 * The exporters this dashboard knows how to read, and the handful of
 * signals from each that matter when something is on fire.
 *
 * **Every metric name here was verified against the exporter's own output**,
 * not written from memory. Each image below was run against a real backing
 * service on 2026-09-26 and its `/metrics` scraped; the names are what came
 * back. That matters because a metric name that does not exist produces
 * silence, not an error — this package shipped five context signals of which
 * four queried names nothing emits, and nobody noticed for months.
 *
 * Verified against:
 *   prom/node-exporter                                 (298 families)
 *   oliver006/redis_exporter        → redis:7-alpine   (189)
 *   oliver006/redis_exporter        → valkey:8-alpine  (198)
 *   prometheuscommunity/postgres-exporter → postgres:16 (355)
 *   prom/mysqld-exporter            → mysql:8          (767)
 *   haproxy:2.9 (built-in exporter, with a backend)     (187)
 *   nginx/nginx-prometheus-exporter → nginx:alpine      (51)
 *   hipages/php-fpm_exporter        → php:8.3-fpm       (94)
 *   prometheuscommunity/elasticsearch-exporter → es:8.15 (176)
 *   percona/mongodb_exporter        → mongo:7          (38, standalone)
 *
 * Three guesses were wrong and are recorded here so they are not made
 * again: HAProxy has no `haproxy_backend_up` (it is `haproxy_backend_status`),
 * PostgreSQL replication lag is `pg_replication_lag_seconds` (not
 * `pg_stat_replication_replay_lag`), and a standalone MongoDB exposes only
 * `mongodb_up` through the Percona exporter — the rest needs a replica set,
 * so nothing else is claimed for it.
 *
 * Signals marked conditional exist only in some deployments (a replica, a
 * cgroup, a PSI-enabled kernel). Discovery reports them as absent without
 * calling it a problem.
 */
final class Catalogue
{
    /**
     * @return list<Exporter>
     */
    public static function all(): array
    {
        return [
            self::node(),
            self::redis(),
            self::postgres(),
            self::mysql(),
            self::haproxy(),
            self::nginx(),
            self::phpfpm(),
            self::elasticsearch(),
            self::mongodb(),
            self::health(),
        ];
    }

    public static function find(string $key): ?Exporter
    {
        foreach (self::all() as $exporter) {
            if ($exporter->key === $key) {
                return $exporter;
            }
        }

        return null;
    }

    private static function node(): Exporter
    {
        return new Exporter('node', 'Host (node_exporter)', '^node_.+', ['instance', 'nodename'], [
            new Signal('load1', 'Load (1m)', 'max(node_load1{{selector}})', 'number'),
            new Signal('cpu', 'CPU busy', '1 - avg(rate(node_cpu_seconds_total{{selector},mode="idle"}[5m]))', 'ratio'),
            new Signal('memory', 'Memory used', '1 - (node_memory_MemAvailable_bytes{{selector}} / node_memory_MemTotal_bytes{{selector}})', 'ratio'),
            new Signal('disk', 'Disk used', '1 - (min(node_filesystem_avail_bytes{{selector},fstype!~"tmpfs|overlay"}) / min(node_filesystem_size_bytes{{selector},fstype!~"tmpfs|overlay"}))', 'ratio'),
            new Signal('disk_io', 'Disk busy', 'max(rate(node_disk_io_time_seconds_total{{selector}}[5m]))', 'ratio'),
            new Signal('net_errors', 'Network errors', 'sum(rate(node_network_receive_errs_total{{selector}}[5m]))', 'per second'),
            new Signal('cpu_pressure', 'CPU pressure', 'rate(node_pressure_cpu_waiting_seconds_total{{selector}}[5m])', 'ratio', conditional: true),
        ]);
    }

    /** Also serves Valkey: the same exporter, and it emits the same names. */
    private static function redis(): Exporter
    {
        return new Exporter('redis', 'Redis / Valkey', '^redis_.+', ['addr', 'instance'], [
            new Signal('up', 'Reachable', 'min(redis_up{{selector}})', 'bool'),
            new Signal('memory', 'Memory used', 'max(redis_memory_used_bytes{{selector}})', 'bytes'),
            new Signal('maxmemory', 'Memory limit', 'max(redis_memory_max_bytes{{selector}})', 'bytes'),
            new Signal('evictions', 'Evictions', 'sum(rate(redis_evicted_keys_total{{selector}}[5m]))', 'per second'),
            new Signal('blocked', 'Blocked clients', 'max(redis_blocked_clients{{selector}})', 'number'),
            new Signal('clients', 'Connected clients', 'max(redis_connected_clients{{selector}})', 'number'),
            new Signal('rejected', 'Rejected connections', 'sum(rate(redis_rejected_connections_total{{selector}}[5m]))', 'per second'),
            new Signal('hit_rate', 'Keyspace hit rate', 'sum(rate(redis_keyspace_hits_total{{selector}}[5m])) / clamp_min(sum(rate(redis_keyspace_hits_total{{selector}}[5m])) + sum(rate(redis_keyspace_misses_total{{selector}}[5m])), 1)', 'ratio'),
        ], describes: 'cache');
    }

    private static function postgres(): Exporter
    {
        return new Exporter('postgres', 'PostgreSQL', '^pg_.+', ['instance', 'server'], [
            new Signal('up', 'Reachable', 'min(pg_up{{selector}})', 'bool'),
            new Signal('connections', 'Connections', 'sum(pg_stat_database_numbackends{{selector}})', 'number'),
            new Signal('max_connections', 'Connection limit', 'max(pg_settings_max_connections{{selector}})', 'number'),
            new Signal('deadlocks', 'Deadlocks', 'sum(rate(pg_stat_database_deadlocks{{selector}}[5m]))', 'per second'),
            new Signal('cache_hit', 'Cache hit rate', 'sum(rate(pg_stat_database_blks_hit{{selector}}[5m])) / clamp_min(sum(rate(pg_stat_database_blks_hit{{selector}}[5m])) + sum(rate(pg_stat_database_blks_read{{selector}}[5m])), 1)', 'ratio'),
            new Signal('locks', 'Locks held', 'sum(pg_locks_count{{selector}})', 'number'),
            new Signal('longest_tx', 'Longest transaction', 'max(pg_stat_activity_max_tx_duration{{selector}})', 'seconds'),
            new Signal('replication_lag', 'Replication lag', 'max(pg_replication_lag_seconds{{selector}})', 'seconds', conditional: true),
        ], describes: 'database');
    }

    private static function mysql(): Exporter
    {
        return new Exporter('mysql', 'MySQL / MariaDB', '^mysql_.+', ['instance'], [
            new Signal('up', 'Reachable', 'min(mysql_up{{selector}})', 'bool'),
            new Signal('connections', 'Threads connected', 'max(mysql_global_status_threads_connected{{selector}})', 'number'),
            new Signal('max_connections', 'Connection limit', 'max(mysql_global_variables_max_connections{{selector}})', 'number'),
            new Signal('running', 'Threads running', 'max(mysql_global_status_threads_running{{selector}})', 'number'),
            new Signal('slow', 'Slow queries', 'sum(rate(mysql_global_status_slow_queries{{selector}}[5m]))', 'per second'),
            new Signal('aborted', 'Aborted connects', 'sum(rate(mysql_global_status_aborted_connects{{selector}}[5m]))', 'per second'),
            new Signal('buffer_pool_hit', 'Buffer pool hit rate', '1 - (sum(rate(mysql_global_status_innodb_buffer_pool_reads{{selector}}[5m])) / clamp_min(sum(rate(mysql_global_status_innodb_buffer_pool_read_requests{{selector}}[5m])), 1))', 'ratio'),
            new Signal('replica_lag', 'Replica lag', 'max(mysql_slave_status_seconds_behind_master{{selector}})', 'seconds', conditional: true),
        ], describes: 'database');
    }

    private static function haproxy(): Exporter
    {
        // 2 means UP in HAProxy's status enum; there is no `_up` metric,
        // which is the kind of thing only reading the real output tells you.
        return new Exporter('haproxy', 'HAProxy', '^haproxy_.+', ['instance'], [
            new Signal('backend_up', 'Backends up', 'min(haproxy_backend_status{{selector},state="UP"})', 'bool'),
            new Signal('servers', 'Active servers', 'sum(haproxy_backend_active_servers{{selector}})', 'number'),
            new Signal('queue', 'Queued requests', 'sum(haproxy_backend_current_queue{{selector}})', 'number'),
            new Signal('response_time', 'Backend response time', 'max(haproxy_backend_response_time_average_seconds{{selector}})', 'seconds'),
            new Signal('conn_errors', 'Connection errors', 'sum(rate(haproxy_backend_connection_errors_total{{selector}}[5m]))', 'per second'),
            new Signal('sessions', 'Frontend sessions', 'sum(haproxy_frontend_current_sessions{{selector}})', 'number'),
        ], describes: 'proxy');
    }

    private static function nginx(): Exporter
    {
        return new Exporter('nginx', 'Nginx', '^nginx_.+', ['instance'], [
            new Signal('up', 'Reachable', 'min(nginx_up{{selector}})', 'bool'),
            new Signal('active', 'Active connections', 'max(nginx_connections_active{{selector}})', 'number'),
            new Signal('waiting', 'Waiting connections', 'max(nginx_connections_waiting{{selector}})', 'number'),
            new Signal('requests', 'Requests', 'sum(rate(nginx_http_requests_total{{selector}}[5m]))', 'per second'),
        ], describes: 'web');
    }

    private static function phpfpm(): Exporter
    {
        return new Exporter('phpfpm', 'PHP-FPM', '^phpfpm_.+', ['instance', 'pool'], [
            new Signal('up', 'Reachable', 'min(phpfpm_up{{selector}})', 'bool'),
            new Signal('active', 'Active workers', 'sum(phpfpm_active_processes{{selector}})', 'number'),
            new Signal('idle', 'Idle workers', 'sum(phpfpm_idle_processes{{selector}})', 'number'),
            new Signal('queue', 'Listen queue', 'max(phpfpm_listen_queue{{selector}})', 'number'),
            // The one that explains "the site just stopped responding".
            new Signal('max_children', 'Max children reached', 'sum(rate(phpfpm_max_children_reached{{selector}}[5m]))', 'per second'),
            new Signal('slow', 'Slow requests', 'sum(rate(phpfpm_slow_requests{{selector}}[5m]))', 'per second'),
        ], describes: 'runtime');
    }

    private static function elasticsearch(): Exporter
    {
        // 0 green, 1 yellow, 2 red.
        return new Exporter('elasticsearch', 'Elasticsearch', '^elasticsearch_.+', ['instance', 'cluster'], [
            new Signal('health', 'Cluster health', 'max(elasticsearch_cluster_health_status{{selector},color="red"})', 'bool'),
            new Signal('unassigned', 'Unassigned shards', 'max(elasticsearch_cluster_health_unassigned_shards{{selector}})', 'number'),
            new Signal('heap', 'JVM heap used', 'max(elasticsearch_jvm_memory_used_bytes{{selector},area="heap"}) / clamp_min(max(elasticsearch_jvm_memory_max_bytes{{selector},area="heap"}), 1)', 'ratio'),
            new Signal('rejected', 'Rejected tasks', 'sum(rate(elasticsearch_thread_pool_rejected_count{{selector}}[5m]))', 'per second'),
            new Signal('breakers', 'Circuit breakers tripped', 'sum(rate(elasticsearch_breakers_tripped{{selector}}[5m]))', 'per second'),
        ], describes: 'search');
    }

    /**
     * Only `mongodb_up` is claimed. A standalone MongoDB exposes nothing
     * else through the Percona exporter — the rest of its collectors need a
     * replica set, so naming them here would be a guess.
     */
    private static function mongodb(): Exporter
    {
        return new Exporter('mongodb', 'MongoDB', '^mongodb_.+', ['instance'], [
            new Signal('up', 'Reachable', 'min(mongodb_up{{selector}})', 'bool'),
        ], describes: 'database');
    }

    /**
     * cboxdk/laravel-health's own Prometheus endpoint. Its namespace is
     * configurable and defaults to `app`, so the pattern is deliberately
     * loose and the selector does the narrowing.
     */
    private static function health(): Exporter
    {
        return new Exporter('health', 'Cbox health', '_health_check_status$', ['instance', 'job'], [
            new Signal('checks', 'Failing checks', 'count(app_health_check_status{{selector}} == 0)', 'number'),
            new Signal('load1', 'Load (1m)', 'max(app_system_cpu_load_1m{{selector}})', 'number'),
            new Signal('memory', 'Memory used', 'max(app_system_memory_usage_ratio{{selector}})', 'ratio'),
            new Signal('disk', 'Disk used', 'max(app_system_disk_usage_ratio{{selector}})', 'ratio'),
            new Signal('oom', 'OOM kills', 'sum(rate(app_container_oom_kills_total{{selector}}[15m]))', 'per second', conditional: true),
            new Signal('throttled', 'CPU throttled', 'sum(rate(app_container_cpu_throttled_total{{selector}}[5m]))', 'per second', conditional: true),
        ], describes: 'host');
    }
}
