<?php
// File Location: api/config/local_secrets.example.php
//
// TEMPLATE ONLY - this file is committed and must never hold a real key.
//
// To send real SMS from local XAMPP:
//   1. Copy this file to  api/config/local_secrets.php
//   2. Put your real Semaphore key in it
//   3. That copy is already gitignored, so it cannot be committed
//
// On Railway none of this is used: the key comes from the service's
// Variables tab as SEMAPHORE_API_KEY, which getenv() picks up first.

define('LOCAL_SEMAPHORE_API_KEY', 'paste-your-semaphore-key-here');
?>
