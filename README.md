# TalerBarr for Dolibarr ERP & CRM

> **Bridge your Dolibarr catalogue with the free‑software payment ecosystem powered by [GNU Taler](https://taler.net)**

---

## Overview

**TalerBarr** is an optional module for Dolibarr that lets you **publish, sell and reconcile products** using a 
self‑hosted [GNU Taler Merchant](https://docs.taler.net/merchant/merchant.html) backend.  
It automates the heavy lifting—price & stock synchronisation, category mapping, tax export—and gives you full 
control over direction of this automatization.

Compatible with **Dolibarr 22**, **Taler v1.0.0+ (v20+ of API) + and PHP 8.0+**
(earlier versions may work but are untested).

---

## Features

Synchronisation of the inventories
<!--
![Screenshot talerbarr](img/screenshot_talerbarr.png?raw=true "TalerBarr"){imgmd}
-->

---

[//]: # (## Screenshots)


[//]: # (---)

## Installation

### 1 · Prerequisites

* A working **Dolibarr ERP & CRM** installation (v20 or newer).
* A reachable **GNU Taler Merchant backend** v1.0.0+.

### 2 · Get the module

#### A Via the Dolibarr GUI (zip)

1. Download the latest release `module_talerbarr‑x.y.z.zip` from [https://www.dolistore.com](https://www.dolistore.com) or the project’s [*Releases*](https://github.com/bohdanpotuzhnyi/talerbarr/releases) tab.
2. Log in as a super‑admin ➜ **Home → Setup → Modules/Application → Deploy/install external app/module**.
3. Upload the zip, then confirm.

#### B Via Git (dev install)

```bash
cd $DOLIBARR_HOME/htdocs/custom
git clone https://github.com/bohdanpotuzhnyi/talerbarr.git
```

If you want a clean Dolibarr tree, create a symbolic link or zip.

### 3 · Enable & configure

1. In Dolibarr: **Setup → Modules / Applications**, locate “**TalerBarr**” and click the toggle.
2. Open the module using the top bar menu, the config page will appear.


---

## Usage

From the moment you have added the correct config, use Dolibarr or Taler as normal, the module will listen to the changes on systems,
and will automatically synchronise the data between the systems.

Additional cron jobs, will trigger re-checking every 24 hours.

Consult the Dolibarr tutorials for info how to use Dolibarr,
as well as Taler Tutorials for info how to use the Taler Merchant

### Recommended Dolibarr modules activated
Enable the following core modules  so TalerBarr can cover the complete order → payout lifecycle:

1. **Products** – enables sync between Dolibarr and Taler for all your products and services.
2. **Stocks** – keeps stock levels in sync between the two systems (stock in, stock out).
3. **Tags / Categories** – maps categories across systems. (Note: Taler doesn’t allow categories on orders — yet.)
4. **Tax** – syncs product-level tax info. Taler only supports taxes through the product, not as standalone per-order rules.
5. **Scheduled jobs** – lets the daily sync and background retries do their thing automatically.
6. **Banks & Cash** – handles the temporary clearing account and final payment reconciliation account.
7. **Invoices** – needed to keep the full Dolibarr flow (order → invoice → clearing → bank) functional and traceable.

---

## Upgrading

Simply deploy the new zip (or `git pull`) and visit **Setup → Modules**. Dolibarr will migrate the database schema if required.

---

## Translations

Language files live under `/langs/`.  Contributions are welcome via pull request.

---

## Contributing

* Fork → feature branch → PR.  Follow the Dolibarr coding style.
* All new PHP must be **type‑hinted** and **PHPStan level 6** clean.
* Please include unit tests whenever feasible.

---

## License

* **Code:** [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.en.html)
* **Documentation & screenshots:** [GFDL 1.3+](https://www.gnu.org/licenses/fdl-1.3.en.html)

> © 2025 TalerBarr contributors.  Dolibarr® is a trademark of the Dolibarr Foundation and is used here for identification purposes only.
