<?php
declare(strict_types=1);

use Codeception\Util\HttpCode;

/**
 * Class ProductsCest
 */
class ProductsCest
{
    public function _before(ApiTester $I): void
    {
    }

    private static function get_erp_api_token(): string
    {
        //as we are not using the framework this needs to be done manually
        $local_registry_file = '../../../app/registry/local.php';
        $registry_data = require($local_registry_file);
        return $registry_data[\Kenashkov\Braiiny\Application\BillyDk::class]['api_token'];
    }

    private static function create_product(ApiTester $I): array
    {
        $prod_name = 'test_prod_'.microtime(true);//unique name
        $params = [
            'product_name' => $prod_name,
        ];
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/admin/products', $params);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $I->seeResponseIsJson();

        list($uuid) = $I->grabDataFromResponseByJsonPath('$.uuid');
        list($product_erp_id) = $I->grabDataFromResponseByJsonPath('$.product_erp_id');

        return [$uuid, $product_erp_id];
    }

    private static function delete_product(ApiTester $I, string $uuid): void
    {
        $I->sendDELETE('/admin/products/'.$uuid);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
    }

        // tests

    /**
     * First creates a product (with a unique name) and then tries to read it)
     * At the end ofthe test the prodcut is deleted
     * @param ApiTester $I
     */
    public function test_product(ApiTester $I): void
    {
        list($uuid) = self::create_product($I);
        try {
            //lets read the product from the application
            $I->sendGET('/admin/products/' . $uuid);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseIsJson();
        } finally { // no matter what happens do a cleanup (and let the exception to bubble up so that the test fails)
            self::delete_product($I, $uuid);
        }
    }

    /**
     * Creates locally a prodcut and checks is it created at the ERP
     * Then the product is deleted
     * And also checks at the ERP is it deleted there
     * @param ApiTester $I
     * @throws Exception
     */
    public function test_local_product_to_erp(ApiTester $I): void
    {

        list($uuid, $product_erp_id) = self::create_product($I);

        try {
            //lets read the product from the ERP
            //need to retrieve the token...
            $api_token = self::get_erp_api_token();
            //we are not going to use the SDK

            $I->haveHttpHeader('X-Access-Token', $api_token);
            $I->sendGET('https://api.billysbilling.com/v2/products/'.$product_erp_id);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseIsJson();
        } finally {
            self::delete_product($I, $uuid);

            //and verify it is also deleted from the ERP
            $I->sendGET('https://api.billysbilling.com/v2/products/'.$product_erp_id);
            $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
            $I->seeResponseIsJson();
        }

    }

    /**
     * Creates a product at the ERP and then imports it and checks is it found locally.
     * Afterthat is deleted locally which should delete it from the ERP as well.
     * @param ApiTester $I
     */
    public function test_product_import_from_erp(ApiTester $I): void
    {
        $prod_name = 'test_prod_'.microtime(true);//unique name
        $api_token = self::get_erp_api_token();
        //we are not going to use the SDK

        $I->haveHttpHeader('X-Access-Token', $api_token);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $params = [
            'product' => [ //just the mandatory fields... the various IDs are taken from braiiny-products\Product - the default ones
                'name'              => $prod_name,
                'organizationId'    => 'cwNMzNn1TOWhrYwyb6jdfA',
                'accountId'         => '4qAjMzZRRoO7sOAjzkorjw',
                'salesTaxRulesetId' => 'K5A89XDhQJeiyC9HtTX6Hw',
            ],

        ];

        $I->sendPOST('https://api.billysbilling.com/v2/products', $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        list($product_erp_id) = $I->grabDataFromResponseByJsonPath('$..id');

        try {
            //import from the ERP into this app
            $I->sendPOST('/admin/products/import-from-erp');
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseIsJson();
            list($imported_products) = $I->grabDataFromResponseByJsonPath('$.imported_products');
            if (count($imported_products) < 1) {
                throw new RuntimeException(sprintf('No products were imported.'));
            }
            //import is OK , lets cleanup locally (this will also delete at the ERP)
            foreach ($imported_products as $uuid) {
                self::delete_product($I, $uuid);
            }
        } catch (\Exception $Exception) {
            //on fail to import just cleanup at the ERP
            $I->sendDELETE('https://api.billysbilling.com/v2/products/'.$product_erp_id);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseIsJson();
            throw $Exception;
        }
    }

    //TODO - add a test on updating a local product that is deleted at the ERP
    //in this case the system does a check before update if the product is not found creates a new one
}
