# Phase 1 report

## Brief

In the picture below you can see the admin-development suite of the module.  
5 objects have been created up to this date; 5 menu entries were added to keep the user in the native Dolibarr environment; 1 trigger file listens for updates from Dolibarr (7 events concerning inventory); and 1 daily cron job makes sure that the inventories stay in sync.

![Module structure](./img/p1_img14.png)

In this picture the files in the opened directories are the script files responsible for the main logic of the module.

![Module_script_files](./img/p1_img18.png)

Tests were developed for both static functions and integration scenarios (which include communication between Dolibarr and the Taler system).  
A CI pipeline was created on GitHub to allow automatic and independent testing of the module.

![Module tests](./img/p1_img15.png)

![Module Travis CI](./img/p1_img16.png)

For the UI you can check the next screenshots:

![Module UI](./img/p1_img19.png)
![Module UI](./img/p1_img20.png)
![Module UI](./img/p1_img21.png)
![Module UI](./img/p1_img22.png)

For the Documentation, it's done as PHPDoc comments in the code:

![Module Documentation](./img/p1_img24.png)

## Long

As stated in the MoU, phase 1 is all about inventory synchronization. In the next screenshot you can see how it works.

First, let’s make sure that we have our module installed and activated.

![Module activation](./img/p1_img1.png)

After this the user can tap the top navigation bar on **“TalerBarr”** and will automatically be transferred to the TalerBarr module page.  
The system checks whether a valid configuration exists; if it is absent it explains this and redirects the user to create one. You can see this in the next picture.

![Module_config](./img/p1_img2.png)

The user has to fill in the data and select the direction of synchronization with the switcher.  
By default it is set to **Dolibarr → Taler**, but the user can change it to **Taler → Dolibarr**.

![Module_config](./img/p1_img3.png)

![Module_config](./img/p1_img4.png)

To make sure we do not store sensitive information such as the user password, we make the login request on the customer side, and Dolibarr receives only a token that is used for future requests.  
The system performs checks to ensure that the received information is correct, saves the updates, and shows the Taler-config card on success.

![Module_config](./img/p1_img5.png)

Now we can check out the module’s home page. It is quite simple, yet it provides 2 essential pieces of information:

1. Status of the configuration (green = healthy, red = something wrong)  
2. Information about the last sync (the amount of inventory data processed and the direction of sync) and a button to run it now.

![Module_home](./img/p1_img6.png)

The product-items page shows information about the products that have been processed by the module—objects available on both systems.

![Module_products](./img/p1_img7.png)

![Module_products](./img/p1_img8.png)

We can also check the list on the Taler side and see that the product is there.

![Taler_product](./img/p1_img9.png)

![Taler_product](./img/p1_img10.png)

Notice that Taler has 6 objects and the 6th object is **SPICEMAN**, created in the testing pipeline.

Let’s switch back to TalerBarr and update the config so it will sync from **Taler to Dolibarr**.  
We can then verify that our product is really fetched.

![Module_config](./img/p1_img11.png)  
![Module_config](./img/p1_img12.png)

The status page now shows **6 / 6**, which is correct because each config check launches a worker that automatically brings both systems up to date.

Let’s verify that the product is present in both Dolibarr and TalerBarr tables.

![Module_products](./img/p1_img13.png)  
![Module_products](./img/p1_img23.png)

This confirms that synchronization works in both directions.
