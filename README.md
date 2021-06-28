
<h1 align="center">TWS License Manager Server</h1>

HANDLE PREMIUM PLUGIN LICENSES WITH YOUR OWN LICENSING SERVER BUILT ON WORDPRESS & WOOCOMMERCE

This plugin is to be installed on WordPress Server where ***License Manager for WooCommerce*** is installed.

## Installation (via Composer):

### Generate required files
- Create a folder (name it as you like, *for eg. **server***).
- Copy files located inside [Config](https://github.com/TheWebSolver/tws-license-manager-server/tree/master/Config) folder into the folder you created above.
- Then from terminal, navigate to the above folder and run:

	```sh
	$ composer install
	```
- Composer will generate required files.

## Activation
- Create a ***zip*** file from the folder created on installation process (*for eg. **server.zip***).
- Upload the zip file to the plugin's directory on your server installation.
- Activate and manage your licenses.

## Modify LMFWC
In order for this plugin to work properly, a change needs to be made to the file in `License Manager for WooCommerce` plugin (*until this feature is added to core*).

- Inside folder `Bin`, there is a file named `Licenses.php`. It is the core file of ***License Manager for WooCommerce*** plugin which is a modified version.
- The modification is done on method `LicenseManagerForWooCommerce\API\v2\Licenses::hasLicenseExpired()` so expired license is handled properly.
- Replace the file `license-manager-for-woocommerce\includes\api\v2\Licenses.php` inside plugin [License Manager for WooCommerce](https://plugins.trac.wordpress.org/browser/license-manager-for-woocommerce/tags/2.2.3/includes/api/v2/Licenses.php) with the file inside the [Bin](https://github.com/TheWebSolver/tws-license-manager-server/tree/master/Bin) folder.

Everything show properly work now!

> ***More Documentation coming soon...***

## Setting Page Screenshots
### General Options
![general][general]
### Storage Options
![storage][storage]
### Checkout Options
![checkout][checkout]

[general]: Screenshots/general.png
[storage]: Screenshots/storage.png
[checkout]: Screenshots/checkout.png