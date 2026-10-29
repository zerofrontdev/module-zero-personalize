# Zero_Personalize — Recommendations with Amazon Personalize

Amazon Personalize learns from the store's orders, carts and wishlists and
recommends:

- **similar products** on the product page ("You may also like"),
- **picks for a signed-in customer** on the home page, the cart and My
  Account ("Picked for you").

Configured in Stores > Configuration > Zero > Recommendations. Replaces the
old Fixstacks_AwsPack (its settings are removed on install).

## Batch mode: what it costs

Personalize can answer in real time, from a campaign or recommender that
runs all the time and is billed by the hour whether anyone visits or not
(about $144 a month per model at the minimum, $270 for a ready-made
e-commerce recommender). This module doesn't use that. Once a week, at
night, it:

1. exports the store's interactions and products to S3,
2. has Personalize train a new version of two solutions,
   `aws-similar-items` and `aws-user-personalization-v2` (automatic
   training is switched off, so Personalize never trains on its own),
3. runs one batch inference job per solution: recommendations for every
   product and for every customer with a history, written to S3,
4. loads the results into `zero_personalize_similar` and
   `zero_personalize_user` and cleans the pages that show them. A
   product's similar ones from its own department (the first level of its
   most specific category: Men, Women, Gear) come first, in the model's
   order: the model learns from every order, mixed carts and test orders
   included, and a men's hoodie shouldn't open on women's tees.

AWS bills the training hours ($0.24 an hour) and the batch recommendations;
nothing runs between runs. A catalog of a few hundred products costs one
or two dollars a run. The storefront reads only the store's own tables: a
product page never waits for AWS and stays in the page cache (Varnish,
CloudFront). The recommendations are a week old at most.

## What it learns from

No tracking script: the store already knows.

| Interaction | From | User |
| --- | --- | --- |
| `Purchase` | order lines (not cancelled orders) | `c<customer id>`, or `g<hash of the email>` for a guest |
| `AddToCart` | quote lines, abandoned carts included | `c<customer id>`, or `q<quote id>` for a guest |
| `Wishlist` | wishlist items | `c<customer id>` |

Of the last 365 days (Learn From the Last), for enabled products visible
in the catalog or search. Products go with their price and top-level
category names. Personalize trains from 1000 interactions and 25 shoppers
with two or more; until then a run says so and costs nothing.

**Demo shoppers** (`--demo-data`, or Train Now With Demo Shoppers) add 150
made-up shoppers who each keep to one top category, the same ones every
run, for a demo store whose orders are test orders. They are `d` users:
they shape the similar products and never get picks of their own. A store
that trained with them keeps them in its weekly runs; train once without
them to stop.

## Setting up AWS

One S3 bucket and one IAM role, made once (Terraform below, as on
demo.zerofront.dev), then:

- **S3 Bucket** and **IAM Role for Personalize** in the configuration (or
  pinned in `app/etc/config.php`, as the Zero repository does);
- the store's own AWS identity (the EC2 instance role, or Access Key ID and
  Secret Access Key for a store outside AWS, kept out of config.php) needs:

```json
[
  {"Effect": "Allow", "Action": "personalize:*", "Resource": "arn:aws:personalize:*:<account>:*"},
  {"Effect": "Allow", "Action": ["personalize:DescribeRecipe", "personalize:ListRecipes"], "Resource": "*"},
  {"Effect": "Allow", "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:ListBucket"],
   "Resource": ["arn:aws:s3:::<bucket>", "arn:aws:s3:::<bucket>/*"]},
  {"Effect": "Allow", "Action": "iam:PassRole", "Resource": "<role arn>",
   "Condition": {"StringEquals": {"iam:PassedToService": "personalize.amazonaws.com"}}}
]
```

The role is trusted by `personalize.amazonaws.com` and may read and write
the bucket; the bucket policy allows `personalize.amazonaws.com` the same
(Personalize checks both). `infra/terraform/personalize.tf` in the Zero
repository makes all of it.

Every resource is named after the **prefix** (default `zero-` and the
store's host name), so one account and one bucket serve several stores and
a development copy.

## Running it

- **Train Now** on the configuration page, or
  `bin/magento zero:personalize:train [--demo-data] [--wait]`: exports and
  starts; `--wait` follows the run to the end (one to three hours).
- The cron `zero_personalize_advance` (every five minutes) takes a run in
  progress further; `zero_personalize_schedule` starts the weekly run on
  its day (Train Every; Never: by hand only).
- `bin/magento zero:personalize:train --reload`: the last run's results
  read again from S3 (kept 30 days), no training.
- `bin/magento zero:personalize:status`: the step, the last run (how much
  data, how many products and customers got recommendations, training
  hours), the resources.
- `bin/magento zero:personalize:teardown --yes`: this store's Personalize
  resources and S3 files deleted, the recommendations emptied.

A failed step stops the run with AWS's own reason on the configuration page
and in `status`. Once the cause is fixed (a permission, a quota),
`zero:personalize:train --resume` takes the run up again from that step;
a new run starts over.

## Storefront

- Product page: in `content.aside`, before the up-sells, the theme's
  up-sell look. Shown products' cache tags and `zero_personalize` on the
  block: a run cleans the pages, a product's change cleans its row.
- Cart and My Account: the customer's picks, rendered with the page (those
  pages aren't cached).
- Home page: an empty slot that loads `zeropersonalize/foryou` (HTMX)
  after the page; the home page stays cached for everyone.

Products that aren't for sale in the store view (disabled, out of stock,
not visible) are left out; with nothing to show, nothing is rendered.

Where it shows: **Enabled** (Recommendations) can be set per website and
store view: off hides both blocks there, on shows them even where the
Default is off. Each block also has its own switch (Storefront > Similar
Products, Picks). The weekly training and the exports follow the Default
value of Enabled only, so switching a store view off doesn't stop them.

## Install

```
composer require zerofront/module-zero-personalize
bin/magento setup:upgrade
```

Magento Open Source 2.4.7–2.4.9, PHP 8.2–8.4, `aws/aws-sdk-php` 3. Unit
tests: `Test/Unit/Model/` (the export, the batch output, a whole run against
the SDK's mock handler, Enabled per scope).
