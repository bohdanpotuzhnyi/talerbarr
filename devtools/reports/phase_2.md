# Phase 2 report

## Brief

### DB-schema adjustments
The database schema was extended to include tables/objects for orders, invoices, and payments.
As well, renaming was done for easier maintaining of the module.
![DB schema phase2](./img2/p2_img8.png)

### Programming
Git diff from the time of phase 1 report to the time of phase 2 report:
![Git diff phase1 to phase2](./img2/p2_img7.png)

### UI
Updates were done to show the order information.
![Module main page](./img2/p2_img6.png)

Update of the main page to reflect the extended information of sync plus more information on config.
![Module main page](./img2/p2_img5.png)

### Testing
New static and integration tests were added to cover the new order payment logic. 

![Orders integration](./img2/p2_img3.png)

![Orders static](./img2/p2_img4.png)

### Documentation

The documentation in code comments, of course, now contains all classes related to invoices and payments.
Also, the restructuring has happened so that it is a bit easier to navigate through it.

![Module Documentation](./img2/p2_img1.png)

![Module Documentation Orders Class](./img2/p2_img2.png) 

## Long 

The main idea of this phase is to extend the functionality of the module to quickly and easily synchronize 
orders information. One of our main goals is to make this module easy to set up for everyone.
So to keep this true, on the initialization of the module we pre-create Taler clearing account and Taler default 
customer, as well we add new payment method "Taler" to Dolibarr payment methods.

Let's show how it will work, firstly, we will try to use Taler Merchant Backend SPAA to create order.

![Taler SPAA create order](./img2/p2_img9.png)

Then we press save, and we have the order information available

![Taler SPAA order created](./img2/p2_img10.png)

Now let's go to Dolibarr and see that the order is already here

![Dolibarr order created](./img2/p2_img11.png)

Great, so now we can provide qr code to customer and pay it with Taler wallet.

![Dolibarr order qr code](./img2/p2_img12.jpg)

![Dolibarr order paid](./img2/p2_img13.jpg)

Payment went through lets' comeback to Dolibarr and check has payment appeared or not.
And we can see that the related object has appeared.

![Dolibarr order payment linked file](./img2/p2_img14.png)

Let's check this object(invoice file)

![Dolibarr order payment linked file view](./img2/p2_img15.png)

As we can see payment went through and all documents were created to show this transaction.
It is fully cleared using the pre-created clearing account.

Now let's try to create order from Dolibarr side (I changed the config to sync from Dolibarr to Taler).
And now inside standard order creation of the Dolibarr. Here we have to select that the payment method is Taler.
![Dolibarr create order](./img2/p2_img16.png)

After we create the order, fill it with goods and validate it. The request will be sent to Taler and the 
order will be created there as well, with the same details.
![Dolibarr order created](./img2/p2_img17.png)

Now we can navigate to Notes page in order and see the Taler status url, which contains qr code which customer can scan
and pay the order using Taler wallet. Where the user can see the order details and pay for it.
![Dolibarr order notes with Taler status url](./img2/p2_img18.jpg)

As user have paid, we can comeback to our Order, check related objects, and see that the status of invoice is already paid.
![Dolibarr order related objects invoice paid](./img2/p2_img19.png)

Let's check the invoice, and it is indeed paid, and cleared on the correct account.
![Dolibarr order invoice paid details](./img2/p2_img20.png)

And as such, sharing the payment link is the only new task that the user has to do to make the payment with Taler.
Everything else is done automatically by the module. Which we believe makes it very easy to use.
