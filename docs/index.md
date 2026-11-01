---
layout: home

hero:
  name: Solo Job Queue
  text: Database-Backed Job Queue for PHP
  tagline: Leased claims with ownership tokens, a retry policy stored with every job, deadlines, and a worker daemon on Doctrine DBAL.
  image:
    src: /logo.svg
    alt: Solo Job Queue
  actions:
    - theme: brand
      text: Get Started
      link: /guide/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/SoloPHP/Job-Queue

features:
  - icon: 🔐
    title: Leased Claims
    details: Each attempt gets a random token and an expiring lease. Only the current attempt can complete, fail or renew its job.
  - icon: 🔁
    title: Retries per Job
    details: Attempts, backoff and deadline are stored with each job when it is enqueued, so no worker setting can change them later.
  - icon: 🗄️
    title: Four Databases
    details: MySQL, MariaDB, PostgreSQL and SQLite through Doctrine DBAL, with SKIP LOCKED claims and retried lock conflicts.
  - icon: 🧹
    title: Maintenance
    details: Abandoned attempts are recovered with each job's own policy, overdue jobs expire, and finished history is pruned in batches.
  - icon: ⚙️
    title: Worker Daemon
    details: One job at a time, graceful shutdown on SIGTERM and SIGINT, limits for jobs, runtime and memory, and timed maintenance.
  - icon: 🔭
    title: Observable
    details: PSR-3 logging with the exception under its key, listener events for metrics and tracing, typed records for inspection.
---

<style>
/* Set on the hero, not :root: the theme's own :root values come later in the built CSS and would win. */
.VPHero {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #f97316 0%, #facc15 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #f9731630 0%, #facc1530 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Quick Example

```php
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\Codec\ContainerJobCodec;
use Solo\JobQueue\Storage\{DbalJobStorage, Schema};
use Solo\JobQueue\Worker\Worker;

Schema::install($connection);

$queue = new JobQueue(
    storage: new DbalJobStorage($connection),
    codec: new ContainerJobCodec($container),
);

$queue->push(new SendEmailJob('user@example.com', 'Welcome'), 'email');

// In a long-running process: claim, run and acknowledge one job at a time.
(new Worker($queue, type: 'email'))->run();
```

## Installation

```bash
composer require solophp/job-queue
```

**Requirements:** PHP 8.3+, `ext-mbstring`, Doctrine DBAL 4, and MySQL 8+, MariaDB 10.6+, PostgreSQL or SQLite 3.35+
