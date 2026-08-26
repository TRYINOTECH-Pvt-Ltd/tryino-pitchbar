/**
 * PM2 ecosystem file for the Pitchbar queue worker.
 *
 * Usage from the project root:
 *
 *   pm2 start ecosystem.config.cjs
 *   pm2 status
 *   pm2 logs pitchbar-queue
 *   pm2 reload ecosystem.config.cjs   # zero-downtime restart after deploy
 *   pm2 stop pitchbar-queue
 *   pm2 save && pm2 startup           # auto-start on server reboot
 *
 * Why --max-time=60 + autorestart=true:
 *   The worker intentionally exits every 60 seconds so PM2 can restart
 *   it. This prevents memory creep, releases stale DB / Redis
 *   connections, and ensures the worker picks up freshly deployed code
 *   without a manual restart.
 *
 * Why kill_timeout=200000:
 *   On `pm2 stop` PM2 sends SIGTERM, then waits this long before
 *   SIGKILL. Laravel's queue worker traps SIGTERM and finishes the
 *   in-flight job before exiting — but a job's --timeout is 180s, so
 *   PM2 must wait at least that long plus buffer or it'll hard-kill
 *   the worker mid-crawl / mid-embed and leave the job in `failed`.
 */
module.exports = {
    apps: [
        {
            name: 'pitchbar-queue',
            cwd: __dirname,
            script: 'php',
            // Queue order is strict priority: list the light, latency-
            // sensitive queues FIRST so a backlog of heavy crawl jobs
            // can't starve them. `analytics` carries the after-stream
            // telemetry + persistence (RecordWidgetEventJob,
            // PersistTurnJob, IncrementUsageJob) — omitting it silently
            // stranded every widget event, turn-persist and usage bump
            // in a queue nothing drained (empty Widget Monitor, lost
            // conversation history). It MUST be in this list.
            args: 'artisan queue:work --queue=analytics,default,index,crawl --max-time=60 --max-jobs=100 --tries=2 --timeout=180 --sleep=3',
            interpreter: 'none',

            // Process model — single fork process, not Node cluster.
            exec_mode: 'fork',
            instances: 1,

            // Restart policy.
            autorestart: true,
            restart_delay: 2000,           // 2s between restarts so we don't hot-loop on a broken DB
            max_restarts: 50,              // guard against runaway restart on permanent failures
            min_uptime: 10000,             // worker must run >= 10s for a restart to "count" toward max_restarts

            // Graceful shutdown — worker has up to 200s to finish in-flight job on SIGTERM.
            kill_timeout: 200000,
            wait_ready: false,
            listen_timeout: 10000,

            // Logging.
            out_file: './storage/logs/pm2-queue-out.log',
            error_file: './storage/logs/pm2-queue-error.log',
            merge_logs: true,
            time: true,                    // prefix every log line with an ISO timestamp

            // Environment.
            env: {
                APP_ENV: 'production',
            },
            env_local: {
                APP_ENV: 'local',
            },
        },

        /**
         * Inertia SSR Node process.
         *
         * Renders Inertia pages server-side so crawlers (own
         * CrawlPageJob, Google, Bing, social previews) receive fully
         * rendered HTML instead of the `<script data-page="app">`
         * JSON shell. Without this, ReadabilityExtractor would strip
         * the script tag and our own crawler bails at line 109 of
         * CrawlPageJob with "Page returned too little content".
         *
         * Why `watch` on the bundle file:
         *   `git pull` replaces bootstrap/ssr/ssr.js in place. PM2
         *   detects the mtime change and restarts the SSR process
         *   automatically — no manual `pm2 reload` needed after
         *   normal deploys. The one-time `pm2 reload
         *   ecosystem.config.cjs` is only required on the very first
         *   deploy that introduces this process entry.
         *
         * Why direct `node` instead of `php artisan inertia:start-ssr`:
         *   Skips a 50-100ms PHP boot per restart and avoids a
         *   double-wrapped process tree. PM2 already owns lifecycle.
         *   The artisan helper is fine for ad-hoc local runs.
         */
        {
            name: 'pitchbar-ssr',
            cwd: __dirname,
            script: 'node',
            args: 'bootstrap/ssr/ssr.js',
            interpreter: 'none',

            exec_mode: 'fork',
            instances: 1,

            autorestart: true,
            restart_delay: 2000,
            max_restarts: 50,
            min_uptime: 10000,

            // SSR doesn't run long jobs — fast SIGTERM is fine.
            kill_timeout: 5000,

            // PM2 restarts pitchbar-ssr whenever the SSR bundle on
            // disk changes. Combined with the build artifact being
            // committed, this makes `git pull` the entire deploy
            // workflow.
            watch: ['bootstrap/ssr/ssr.js'],
            ignore_watch: ['node_modules', 'storage', 'public', '.git'],

            out_file: './storage/logs/pm2-ssr-out.log',
            error_file: './storage/logs/pm2-ssr-error.log',
            merge_logs: true,
            time: true,

            env: {
                APP_ENV: 'production',
                NODE_ENV: 'production',
            },
            env_local: {
                APP_ENV: 'local',
                NODE_ENV: 'development',
            },
        },
    ],
};
