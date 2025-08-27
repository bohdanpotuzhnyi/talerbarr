# Webhook directory to listen for webhook from Taler

2.11. Setting up a webhook
To receive notifications when a purchase has been made or a refund was given to a wallet, you can set up webhooks in the GNU Taler merchant backend. Webhooks allow you to trigger HTTP(S) requests based on certain events. A webhook is thus simply an HTTP request that the GNU Taler merchant backend will make when a certain event (such as a payment) happens.

There are various providers that can send an SMS to a phone number based on an HTTP request. Thus, by configuring such a provider in a webhook you can receive an SMS notification whenever a customer makes a payment.

Webhooks are configured per instance. In the Webhook configuration, you can specify which URL, which HTTP headers, which HTTP method and what HTTP body to send to the Webhook. Webhooks are automatically retried (with increasing delays) when the target server returns a temporary error.

Mustach templates and limited version of it are used when defining the contents of Webhooks. Depending on the triggering event, the templates will be expanded with event-specific data. Limited in this case means that only a specific string is being replaced with the event-specific data, no support for parsing conditions or nested structures is provided.

2.11.1. Order pay events
For “pay” events, the backend will provide the following information to the Mustache templating engine:

contract_terms: the contract terms of the paid order.

order_id: the ID of the order that received the refund.

2.11.2. Order refund events
For “refund” events, the backend will provide the following information to the Mustache templating engine:

timestamp: time of the refund (in nanoseconds since 1970).

order_id: the ID of the order that received the refund.

contract_terms: the full JSON of the contract terms of the refunded order.

refund_amount: the amount that was being refunded.

reason: the reason entered by the merchant staff for granting the refund; be careful, you probably want to inform your staff if a webhook may expose this information to the consumer.

2.11.3. Order settled events
For “order_settled” events, the backend will provide the following information to the limited Mustache templating engine:

order_id: The unique identifier of the order that has been fully settled (all payments completed and wired to the merchant).

wtid: The wire transfer ID of the settlement.

2.11.4. Category added events
For “category_added” events, the backend will provide the following information to the limited Mustache templating engine:

webhook_type: “category_added”.

category_serial: The unique identifier of the newly added category.

category_name: The name of the newly added category.

merchant_serial: The unique identifier of the merchant associated with the category.

2.11.5. Category updated events
For “category_updated” events, the backend will provide the following information to the limited Mustache templating engine:

webhook_type: “category_updated”.

category_serial: The unique identifier of the updated category.

old_category_name: The name of the category before the update.

category_name: The name of the category after the update.

category_name_i18n: The internationalized name of the category after the update.

old_category_name_i18n: The internationalized name of the category before the update.

2.11.6. Category deleted events
For “category_deleted” events, the backend will provide the following information to the limited Mustache templating engine:

webhook_type: “category_deleted”.

category_serial: The unique identifier of the deleted category.

category_name: The name of the deleted category.

2.11.7. Inventory added events
For “inventory_added” events, the backend will provide the following information to the limited Mustache templating engine:

webhook_type: “inventory_added”.

product_serial: The unique identifier of the newly added product.

product_id: The ID of the newly added product.

description: The description of the newly added product.

description_i18n: The internationalized description of the newly added product.

unit: The unit of the newly added product.

image: The image of the newly added product.

taxes: The taxes of the newly added product.

price: The price of the newly added product.

total_stock: The total stock of the newly added product.

total_sold: The total sold of the newly added product.

total_lost: The total lost of the newly added product.

address: The address of the newly added product.

next_restock: The next restock of the newly added product.

minimum_age: The minimum age for buying the newly added product.

2.11.8. Inventory updated events
For “inventory_updated” events, the backend will provide the following information to the limited Mustache templating engine:

webhook_type: “inventory_updated”.

product_serial: The unique identifier of the updated product.

product_id: The ID of the product.

old_description: The description of the product before the update.

description: The description of the product after the update.

old_description_i18n: The internationalized description of the product before the update.

description_i18n: The internationalized description of the product after the update.

old_unit: The unit of the product before the update.

unit: The unit of the product after the update.

old_image: The image of the product before the update.

image: The image of the product after the update.

old_taxes: The taxes of the product before the update.

taxes: The taxes of the product after the update.

old_price: The price of the product before the update.

price: The price of the product after the update.

old_total_stock: The total stock of the product before the update.

total_stock: The total stock of the product after the update.

old_total_sold: The total sold of the product before the update.

total_sold: The total sold of the product after the update.

old_total_lost: The total lost of the product before the update.

total_lost: The total lost of the product after the update.

old_address: The address of the product before the update.

address: The address of the product after the update.

old_next_restock: The next restock of the product before the update.

next_restock: The next restock of the product after the update.

old_minimum_age: The minimum age for buying the product before the update.

minimum_age: The minimum age for buying the product after the update.

2.11.9. Inventory deleted events
For “inventory_deleted” events, the backend will provide the following information to the limited Mustache templating engine:

webhook_type: “inventory_deleted”.

product_serial: The unique identifier of the deleted product.

product_id: The ID of the deleted product.

description: The description of the deleted product.

description_i18n: The internationalized description of the deleted product.

unit: The unit of the deleted product.

image: The image of the deleted product.

taxes: The taxes of the deleted product.

price: The price of the deleted product.

total_stock: The total stock of the deleted product.

total_sold: The total sold of the deleted product.

total_lost: The total lost of the deleted product.

address: The address of the deleted product.

next_restock: The next restock of the deleted product.

minimum_age: The minimum age for buying the deleted product.

