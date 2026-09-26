---
title: Recalibration retries use a time window and exception cap
description: Lock contention retries until a deadline while genuine exceptions remain bounded
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-26
code_refs:
  - app/Jobs/Run/RecalibrateTrainingHistoryJob.php
  - config/queue.php
---

# Recalibration retries use a time window and exception cap

**Status:** Accepted (2026-09-26). Supersedes the retry-limit statement in [[plan-recalibration-job-horizon]].

The job’s [retry deadline](app/Jobs/Run/RecalibrateTrainingHistoryJob.php:40) is ten minutes, and its [exception cap](app/Jobs/Run/RecalibrateTrainingHistoryJob.php:20) limits genuine failures to three. A [lock-contention release](app/Jobs/Run/RecalibrateTrainingHistoryJob.php:74) does not spend that cap. With a [120-second execution timeout](app/Jobs/Run/RecalibrateTrainingHistoryJob.php:27) and [Redis retry-after of 420 seconds](config/queue.php:74), a consistently timed-out history gets about two executions before the deadline. The recalculation remains atomic, and issue #1249 tracks the resumable follow-up.
