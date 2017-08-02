Magento2-iTransact
======================

iTransact payment gateway Magento2 extension


Install
=======

1. Go to Magento2 root folder

2. Enter following commands to install module:

    ```bash
    composer config repositories.camronlevanger git https://github.com/camronlevanger/magento2_itransact.git
    composer require camronlevanger/itransact:dev-master
    ```
   Wait while dependencies are updated.

3. Enter following commands to enable module:

    ```bash
    php bin/magento module:enable CamronLevanger_Itransact --clear-static-content
    php bin/magento setup:upgrade
    ```
4. Enable and configure iTransact in Magento Admin under Stores/Configuration/Payment Methods/iTransact

Other Notes
===========

I make no warranties or guarantees on this extension. I wrote it for my own educational purposes. Since the only Magento 2 iTransact extensions available are over $100 US I decided to make available an open source version. If you like it, please help out by getting involved with the project.

