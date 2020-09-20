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

    // tests

    /**
     * First creates a product (with a unique name) and then tries to read it)
     * At the end ofthe test the prodcut is deleted
     * @param ApiTester $I
     */
    public function test_product(ApiTester $I): void
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

        try {
            //lets read the product from the application
            $I->sendGET('/admin/products/' . $uuid);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseIsJson();
        } finally { // no matter what happens do a cleanup (and let the exception to bubble up so that the test fails)
            $I->sendDELETE('/admin/products/'.$uuid);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseIsJson();
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
            //cleanup - delete the product
            $I->sendDELETE('/admin/products/'.$uuid);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseIsJson();

            //and verify it is also deleted from the ERP
            $I->sendGET('https://api.billysbilling.com/v2/products/'.$product_erp_id);
            $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
            $I->seeResponseIsJson();
        }

    }


}
