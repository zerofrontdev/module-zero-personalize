# Zero_Personalize — status and what comes next

**Last updated:** 2026-11-07

## Done

- Amazon Personalize in batch mode (export, import, train, batch inference,
  load results) driven step by step by `Model\Pipeline`; see README.
- Pipeline lock (2026-10-31, ZERO-REVIEW A8-7): `start`, `resume`, `reload`
  and `cancel` run under the `zero_personalize_pipeline` lock and refuse with
  a message while another step holds it; the cron's `advance()` skips the tick
  instead. `start()` checks "running" again under the lock.
- Train Now is POST only (data-post with the form key); the GET route is gone.
- Guest ids are a keyed hash (`EncryptorInterface::hash`, 'g' + 16 chars)
  instead of a plain sha1 of the email (2026-10-31, ZERO-REVIEW A8-L7).

- Tests (2026-10-31): `PipelineTest` with an in-memory lock manager
  (`Test/Unit/Fake/FakeLockManager`, which refuses a lock already held, so a
  nested lock fails the test): refused start/resume/reload/cancel, a skipped
  `advance()`, one lock per call, the re-check under the lock;
  `ExporterTest::testGuestIdsAreKeyedHashes`; `StartTest` (POST only);
  `StatusTest` (`postData()` sends `data` as an object). E2E
  `dev/tests/zero-e2e/tests/personalize-admin.spec.ts` checks the buttons'
  data-post and that a GET of the action answers 404, without clicking.

- Cost levers (2026-11-07): Picks for Customers Active in the Last (days),
  Most Customers per Run, Recommendations per Product and Customer (Config
  `customerDays`/`maxCustomers`/`batchResults`; `Pipeline::pickCustomers`,
  tested). Defaults keep the old behaviour (all customers of the Learn From
  period, 12 results). Public since 1.3.7; this change is not released yet.

## Next

- Add the `personalize-admin` e2e project (serial, admin session) to
  `playwright.config.ts` in the same commit as the spec (until then the
  spec runs inside the storefront projects, in parallel admin sessions),
  and run it once. Check that it reports passed, not skipped: without the
  S3 bucket and IAM role set (or with a run in progress) no Train Now
  button renders and the spec skips, so the data-post markup is then only
  proven by `StatusTest`. Also confirm the admin GET of
  `zero_personalize/training/start` really answers 404; adjust the
  assertion if Magento answers otherwise.
- Admin check by hand: both Train Now buttons POST and start a run (the e2e
  never clicks them: a run bills AWS).

## Traps

- The first export after the keyed-hash change gives every guest a new id;
  only customer (`c`) ids are used downstream, so recommendations are not
  lost, but the guest history in the dataset starts over.
- `mage/dataPost` needs `data` as a JSON object: an empty PHP array encodes
  as `[]`, the form key is then never posted and the admin rejects the
  request (`Block\Adminhtml\Status::postData()` sends `{}`).
