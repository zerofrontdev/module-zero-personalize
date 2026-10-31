# Changelog: zerofront/module-zero-personalize

## 1.0.4

- Train Now is a POST with the form key (a training run bills AWS), and training steps share a lock, so the cron and the admin never start two imports or trainings.
- A guest's orders are exported under a keyed hash of the email (HMAC with the install's key) instead of a plain SHA-1 of it, so the id says nothing outside the store. The next training sees those guests as new users.

Earlier releases (1.0.3 and before): no release notes.
