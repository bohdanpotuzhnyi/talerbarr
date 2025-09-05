# TalerBarr for Dolibarr ERP & CRM

> **Bridge your Dolibarr catalogue with the free‑software payment ecosystem powered by [GNU Taler](https://taler.net)**

---

## Overview

**TalerBarr** is an optional module for Dolibarr that lets you **publish, sell and reconcile products** using a 
self‑hosted [GNU Taler Merchant](https://docs.taler.net/merchant/merchant.html) backend.  
It automates the heavy lifting—price & stock synchronisation, category mapping, tax export—and gives you full 
control over direction of this automatization.

Compatible with **Dolibarr 20 → 22** and **Taler v1.0.0+ (v20+ of API) +**
(earlier versions may work but are untested).

---

## Features

Syncronisation of the inventories
<!--
![Screenshot talerbarr](img/screenshot_talerbarr.png?raw=true "TalerBarr"){imgmd}
-->

---

## Screenshots

<!--
Add your screenshots to `img/` and remove comment markers.

![Product sync dashboard](img/screenshot_talerbarr_dashboard.png)
-->

---

## Installation

### 1 · Prerequisites

* A working **Dolibarr ERP & CRM** installation (v20 or newer).
* A reachable **GNU Taler Merchant backend** v1.0.0+.

### 2 · Get the module

<details>
<summary><strong>A&nbsp;·&nbsp;Via the Dolibarr GUI (zip)</strong></summary>

1. Download the latest release `module_talerbarr‑x.y.z.zip` from [https://www.dolistore.com](https://www.dolistore.com) or the project’s *Releases* tab.
2. Log in as a super‑admin ➜ **Home → Setup → Modules/Application → Deploy/install external app/module**.
3. Upload the zip, then confirm.

</details>

<details>
<summary><strong>B&nbsp;·&nbsp;Via Git (dev install)</strong></summary>

```bash
cd $DOLIBARR_HOME/htdocs/custom
git clone https://github.com/bohdanpotuzhnyi/talerbarr.git
```

If you want a clean Dolibarr tree, create a symbolic link or zip.

</details>

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
