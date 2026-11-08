# Changelog: zerofront/module-zero-personalize

## 1.3.8

- New settings in Stores > Configuration > Zero > Recommendations > Training: "Picks for Customers Active in the Last (days)", "Most Customers per Run" and "Recommendations per Product and Customer". The weekly batch bills every product and every customer, so a big store can now cut the bill; the defaults keep the old behaviour (all customers of the Learn From period, 12 recommendations).
- The README has a cost table by store size, from AWS list prices.
- A customer left out of a run sees no "Picked for you" until they are active again; product pages keep their similar products.

## 1.3.7

- New in the free set (it was a licensed module before): similar products on the product page and picks for every signed-in customer, from the store's own orders, carts and wishlists.
- Amazon Personalize runs in batch mode on your own AWS account: a weekly train and one batch of recommendations, nothing billed between runs. The module does nothing until you set the S3 bucket and the IAM role; the README lists the policies they need.
- Needs the Zero theme with Zero Core and Zero Checkout.

Earlier releases (1.3.5 and before): see the Git tags.
