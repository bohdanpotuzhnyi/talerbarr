# Phase 3 report
In this report, you will be able to see what features have been done in the last couple of months.
And how you can re-create the same behaviour.
Generally, it proves that work in the field of the DB-schema adjustments + Programming + UI has been done.
Additionally, info on the Testing and Documentation will be available atfer showcase of the delivered features.

## 2FA support
This feature is not something that was on the roadmap(of this project), but due to the fact that a Taler team actively 
worked on the multi-tenant merchant backend. Plus, multi-tenancy is one of the easiest ways to start working as a 
merchant with Taler(e.g., using my.taler-ops.ch), this feature is necessary to let people use this module who already have 
the instance on my.taler-ops.ch. Pretty much, all the Biel/Bienne CH merchants that provide Taler payments.

You can always get an instance on my.taler-ops.ch, and try it yourself, on the dolibarr instance provided to you.
Otherwise, here are screenshots which show how it works:

1. Enter the details on the instance we want to connect to in my case it is my.taler-ops.ch, username and password are 
ones of the instance I have received from developers

![2FA login 1](./img3/2fa_login_1.png)

2. On pressing of the "Save" button, Module makes a request to my.taler-ops.ch, and depending on the answer, either receives the token if no
2FA is required, or shows the next screen on which users can choose how they want to verify their identity, in this case
it is either SMS or EMAIL. Check the next picture.

![2FA login 2](./img3/2fa_login_2.png)

3. After the user confirms the method, the module requests the code and makes sure it was sent, can be checked on the next 2 pictures

![2FA login 3](./img3/2fa_login_3.png)

![2FA login 4](./img3/2fa_login_4.png)

4. The user has to enter code, received via a selected verification method.

![2FA login 5](./img3/2fa_login_5.png)

5. After the user press the "Verify code" button, the module makes a request to backend and finally receives the token.

![2FA login 6](./img3/2fa_login_6.png)

6. After code is confirmed and token received, user is being sent to the config card page.

![2FA login 7](./img3/2fa_login_7.png)

# Sync on order is paid

This is something that was on the roadmap, and it is useful in the case where the customer has a vending machine or coffee machine or ... and wants
for the orders to appear in its ERP system when the order actually happened, so meaning that all just select and check price
will not create a bunch of open orders that would never be finished. 

Firstly, we can go to the order page on dolibarr and check which orders are there ("/commande/list.php?leftmenu=orders").
From next pictures we can notice that there is 32 orders on dolibarr system and 27 orders on Talerbarr module.

![Order from Taler on order paid 1](./img3/oftoop_1.png)

![Order from Taler on order paid 2](./img3/oftoop_2.png)

Now we can proceed to the Taler to create order. On next 2 pictures, you can see how order is created on Taler SPAA.

![Order from Taler on order paid 3](./img3/oftoop_3.png)

![Order from Taler on order paid 4](./img3/oftoop_4.png)

We can now comeback to Dolibarr and check that the order is not in the Dolibarr orders

![Order from Taler on order paid 5](./img3/oftoop_5.png)

Yet we can now see in the Talerbarr orders, that info about the new order has been detected. Yet we can see that there
is no order, payment, refund or wire transfer links, that are on the Dolibarr.

![Order from Taler on order paid 6](./img3/oftoop_6.png)

Additionally, we can go to this talerbarr order link card, and check that there is only general info recorded.

![Order from Taler on order paid 7](./img3/oftoop_7.png)

We can now proceed to the pay the order using Taler wallet, confirmation is seen on the next screen.

![Order from Taler on order paid 8](./img3/oftoop_8.png)

and on the next screen we can see that the Taler SPAA has seen that the order has been paid.

![Order from Taler on order paid 9](./img3/oftoop_9.png)

Now we can comeback to the orders page of the Dolibarr and check that the new order has appeared.

![Order from Taler on order paid 10](./img3/oftoop_10.png)

As well we can go to out table of module orders page, and see that there is an update to previous version, by the fact
that it has linked elements.

![Order from Taler on order paid 11](./img3/oftoop_11.png)

Additionally, we can go to the talerbarr order card, and check that there are update to the object.

![Order from Taler on order paid 12](./img3/oftoop_12.png)

We can now see that all objects such as commande(order), facture(payment), and payment is linked and can be accessed
from this place.

## Refunds

Here mainly 3 paths are possible:
1. The refund can be triggered from Taler SPAA.
2. The refund can be triggered from a Dolibarr invoice via create credit note.
3. The refund can be triggered from the Talerbarr order card using the "Refund" button.

In the next subsections, you will be able to see how to use any of them. As this behaviour is not connected to the sync
in specific directions, and it works always, we will showcase it on the previous set-up.

For all of these stages, I have skipped the part where I create and pay orders, but all of the refunds
shown in the next sections are completely normal orders, which were created with steps described as for the **Sync on order is paid**.

### Refund triggered from Taler SPAA

We start with having an order which was paid. Example can be seen on the next picture:

![Refund from Taler SPAA 1](./img3/rft_1.png)

Now we create the refund using the button on Merhcnat SPAA.

![Refund from Taler SPAA 2](./img3/rft_2.png)

Now we can switch to the card at Dolibarr and see that the refund is now in the process.

![Refund from Taler SPAA 3](./img3/rft_3.png)

We can collect the refund with Taler wallet, and see that the refund has happened on Taler system.

![Refund from Taler SPAA 4](./img3/rft_4.png)

We can now comeback to the Dolibarr and check that the refund is also registered there, on the Talerbarr order card.

![Refund from Taler SPAA 5](./img3/rft_5.png)

### Refund triggered from a Dolibarr invoice via create credit note

As usually we start with having an order which was paid, the order with which we will work is shown on next picture:

![Refund from Dolibarr credit note 1](./img3/rfcn_1.png)

Now we go to the invoice in this order and then select the "Create credit note" button, next screen will show up. On
this one we mainly have to enter that the payment method is Taler(because if it will not be indicated, the refund 
wouldn't be created on Taler system), and of course enter the invoice date, and press save.

![Refund from Dolibarr credit note 2](./img3/rfcn_2.png)

After the save was pressed the provisional document has been created by dolibarr, we then have to select the items that 
 have been refunded, and press validate. 

![Refund from Dolibarr credit note 3](./img3/rfcn_3.png)

After the validation, the system will verify that all conditions for refund are still
here, and in this case, will just create the refund on Taler system. As soon as it is added note will be added
to this credit note (you can see it by "1" being in the top bar near the Notes tab)

![Refund from Dolibarr credit note 4](./img3/rfcn_4.png)

Now we can comeback to our Talerbarr order card and also verify that the order refund has been tracked and is in process,
after this Taler wallet can collect the refund, and the information will be updated as in the 2 previous pictures of previous
refund path.

### Refund triggered from the Talerbarr order card using the "Refund" button

This is definitely, not the best approach in terms of resource management, but it allows to make refund quickly and without
need to leave Dolibar system. As usual, we start with having an order which was paid.

![Refund from Talerbarr order card 1](./img3/rfdo_1.jpeg)

We press the "Refund" button, and the form will show up, on which refund amount will be pre-entered it still can be changed, 
and reason for the refund has to be entered. After this, we press "Create Taler refund" button on this form.

![Refund from Talerbarr order card 2](./img3/rfdo_2.jpeg)

On next screen confirmation can be seen, as well as the fact that the refund is in process, then merchant can use status link
or go to the linked credit note, and in the notes there, also find the refund link.

![Refund from Talerbarr order card 3](./img3/rfdo_3.jpeg)

In the status page the qr-code is shown(applicable to previous 2 use cases also)

![Refund from Talerbarr order card 4](./img3/rfdo_4.jpeg)

After the wallet collects the refund, the status is updated on Taler system, and then on Dolibarr as well.

![Refund from Talerbarr order card 5](./img3/rfdo_5.jpeg)

## Wire transfer

This is the last major feature that was implemented in this phase. It is just a last step, in making the order flow 
complete.

To make it a bit faster, we create an order on taler, which has a small payment, refund and wire deadlines, as shown on
the next picture:

![Wire transfer 1](./img3/wire_1.png)

After the order is created and paid, we can briefly verify that the order in the Talerbarr order card is updated with
the payment info. Now we wait for 2 minutes for the payment to be cleared, and then we can check that the wire 
transfer is created.

![Wire transfer 2](./img3/wire_2.png)

As 2 minutes passed, we can see on Taler SPAA, that the order is cleared, and the wire transfer is created.

![Wire transfer 3](./img3/wire_3.png)

Now we can come back to the Dolibarr and check that the wire transfer is also created there, on the Talerbarr order card.
Here we can see info about the wire transfer and as well the link to the related object in Dolibarr, which is transfer 
between to accounts (clearing + bank account).

![Wire transfer 4](./img3/wire_4.png)

## General updates

In addition to the order flow features, some general updates were made, which included the update of the ui/ux of the 
module, as example the main screen now has statistic diagrams, and main info from the module on orders/inventory and 
config and sync. As well, as order screen has been updated majorly, to show info in more readable way comparing to phase 2.
Tables has also been updated, to show more info in them, giving quick access to most relevant info.

![General updates 1](./img3/updated_index_page.jpg)


## Testing

This might have been one of the biggest changes in the last months, as we had to prepare the project to be stored at Taler
git, and of course for this we had to prepare a system in a way that it can work on buildbot provided by Taler. This transition
will be finalized on the next milestone. Yet now every developer of this module, can run tests on their local machine for
this the command has to be called from the Dolibarr root folder.

```bash
./htdocs/custom/talerbarr/devtools/podman/run-tests-podman.sh --local-module
```

The new stages have been introduced to test that refunds and wire transfers are working properly. Some problems with
 the webhook test of the wire transfer were faced, which is now described with the bug number #0011139, so this of course
will not be requested. The possible problem is nearly fully connected to the fact how the taler exchange bank is configured 
on the Taler demo system, because with simulation of info from the webhook tests are passing, as shown on next picture.

![Testing](./img3/github_test_suite.png)

## Documentation

Is as always available in the code, and can be generated using the phpDocumentor(as in the previous phases). In the next
phase tutorials would be added, to ease the module onboarding.

