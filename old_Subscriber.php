<?php
namespace ErpSpecialPrice\Subscriber;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Session\SessionServiceInterface;

use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemQuantityChangedEvent;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Defaults;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;

//use Shopware\Core\Checkout\Cart\Rule\LineItemCustomFieldRule;

// to add a coupon as a line item for AG discount
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
//https://developer.shopware.com/docs/guides/plugins/plugins/checkout/cart/add-cart-items
error_reporting(0);
class Subscriber implements EventSubscriberInterface
{   
    //private ?Connection $connection;
    private $productRepository;
    private $ruleRepository;
    private $tagRepository;
    private $promotionRepository;
    private $customerRepository;
    private $si_variable;
    private $productPriceRepository;
    private $customerGroup_names;
    private $total_ag_discounts;
    private $cart;    
    private EntityRepository $customerTagRepository;
    private $requestStack;
    private $session;

    CONST GET_SPECIAL_PRICE_API_URL= 'https://erp-api.hochhausshop.tma-server.de/getSpecialPrice.php';

    public function __construct(RequestStack $requestStack, EntityRepository $productRepository, EntityRepository $ruleRepository, EntityRepository $tagRepository, EntityRepository $promotionRepository, EntityRepository $customerRepository, EntityRepository $productPriceRepository,
        EntityRepository $customerTagRepository )
    {
        $this->productRepository = $productRepository;
        $this->ruleRepository = $ruleRepository;
        $this->tagRepository = $tagRepository;
        $this->promotionRepository = $promotionRepository;
        $this->customerRepository = $customerRepository;
        $this->productPriceRepository = $productPriceRepository;
        $this->customerTagRepository = $customerTagRepository;
        $this->requestStack = $requestStack;

        // Get the current request from the RequestStack
        $request = $this->requestStack->getCurrentRequest();

        // Retrieve the session object from the request
        $this->session = $request->getSession();

    }
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onBeforeItemAddedIntoCart',
            BeforeLineItemQuantityChangedEvent::class => 'onBeforeItemQuantityChanged',
            BeforeLineItemRemovedEvent::class => 'onBeforeLinItemRemoved',
            CheckoutOrderPlacedEvent::class => 'onAfterOrderPlaced'
        ];
    }
    public function onAfterOrderPlaced(CheckoutOrderPlacedEvent $event)
    {
        $order = $event->getOrder();
        $orderedLineItems = $order->getLineItems();
        $customerNumber = $order->getOrderCustomer()->getCustomerNumber();
        foreach ($orderedLineItems as $key => $orderedLineItem) {
            $productNumber = $orderedLineItem->getPayload()['productNumber'];
            $this->deleteExistingPromotion($event, $customerNumber, $productNumber);
        }
        // remove AG promotion
        $si_cart = $this->session->get('si_cart');          
        $cartId = $si_cart['cartId'];
        $promotion_name = "AG_Promotion_".$customerNumber."-".$cartId;
        $this->deleteAGExistingPromotion($event, $customerNumber, $cartId);

        // remove the session variable
        $this->session->remove('si_cart');
    }
    public function onBeforeItemAddedIntoCart(BeforeLineItemAddedEvent $event)
    {
        $this->processSpecialPrice($event, 'BeforeLineItemAddedEvent');
    }
    public function onBeforeItemQuantityChanged(BeforeLineItemQuantityChangedEvent $event)
    {
        $this->processSpecialPrice($event, 'BeforeLineItemQuantityChangedEvent');
    }
    public function onBeforeLinItemRemoved(BeforeLineItemRemovedEvent $event)
    {
        if (null !== $event->getSalesChannelContext()->getCustomer()) {
            $customer = $event->getSalesChannelContext()->getCustomer();
            $customerNumber = $customer->getCustomerNumber();
            $productNumber = $event->getLineItem()->getPayload()['productNumber'];
            $this->deleteExistingPromotion($event, $customerNumber, $productNumber);
            $productId = $event->getLineItem()->getId();
            $si_cart = $this->session->get('si_cart');
            unset($si_cart['items'][$productId]);
            $this->session->set('si_cart', $si_cart);
            $cartId = $event->getCart()->getToken();
            $promotion_name = "AG_Promotion_".$customerNumber."-".$cartId;
            $this->deleteAGExistingPromotion($event, $customerNumber, $cartId);

        }
    }
    private function processSpecialPrice($event, $eventName = '')
    {        


        $customer = $event->getSalesChannelContext()->getCustomer(); 
        $customerNumber = $customer->getCustomerNumber();

        // remove AG promotion it is only for this promotion, otherwise it will apply always        
         $this->deleteAllAGExistingPromotion($event, $customerNumber);

        $si_cart = array();
        /* 
            Check if customer is logged in or not, proceed if only customer is logged in
        */
        if (null !== $event->getSalesChannelContext()->getCustomer()) {           

            // session cart                       
            $si_cart = $this->session->get('si_cart');   
            $si_items = $si_cart['items'] ;
            
            $lineItem = $event->getLineItem();

            /*$customFields = $lineItem->getPayloadValue('customFields');
            print_r($customFields);die();*/

            $productId = $lineItem->getId();
            $currencyId = $event->getSalesChannelContext()->getCurrency()->getId();
            
            $productQuantityToBeConsidered = $lineItem->getQuantity() + $this->lineItemQuantityInCart($event, $productId, $eventName);
            $customerId = $customer->getId();
            
            $customerGroupUUID = $customer->getGroupId();//die();
            /* Get product details */
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $lineItem->getReferencedId()));
            $product = $this->productRepository->search($criteria, $event->getContext())->first();
            //echo '<pre>';print_r($product);die();
            $productNumber = $product->getProductNumber();
            $cheapestPriceRuleId = $product->getCheapestPrice()->getRuleId();

            if(null === $cheapestPriceRuleId){
                $currentPriceRuleId = 'default';
                $currentProductPrice = $product->getPrice()->getElements()[$currencyId]->getNet();
            }            
            
            // get prices
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('productId', $productId));

            // fetch prices from database
            $product_prices_obj = $this->productPriceRepository->search($criteria, $event->getContext());
            $product_price_lists = $product_prices_obj->getEntities()->getElements();
            //echo '<pre>';print_r($product_price_lists);die();
            
            $price_list_rule_names = [];
            
            foreach ($product_price_lists as $k => $product_price_obj) {
                $rule_id = $product_price_obj->getRuleId();
                $quantityStart = $product_price_obj->getQuantityStart();
                $quantityEnd = $product_price_obj->getQuantityEnd();
                $rule_id = $product_price_obj->getRuleId();
                $product_price_collection = $product_price_obj->getPrice()->getElements()[$currencyId];
                                
                $criteria = new Criteria();               
                
                $criteria->addFilter(new EqualsFilter('id', $rule_id));
                $tag = $this->ruleRepository->search($criteria, $event->getContext())->first();
                if (null !== $tag) {
                   $rule_name = $tag->getName();                    
                   $price_list_rule_names[$rule_name] = $rule_id;
                }    

                $si_prices[$rule_id][$quantityStart.'-'.$quantityEnd] = ['quantityStart' => $quantityStart, 'quantityEnd' => $quantityEnd, 'net' => $product_price_collection->getNet(), 'gross' => $product_price_collection->getGross() ];
                // find current product price
                if($cheapestPriceRuleId == $rule_id && $productQuantityToBeConsidered >= $quantityStart && ( $productQuantityToBeConsidered < $quantityEnd || $quantityEnd == '') ){
                    $currentProductPrice = $product_price_collection->getNet();
                    $currentPriceRuleId = $rule_id;
                }
                
            } 
            $si_cart[$productId]['rules'] = $price_list_rule_names;//array_merge($si_cart['rules'], $price_list_rule_names);
            // set 
            $cheapest_price = $currentProductPrice;
            
            //$product->getCheapestPriceContainer()
            // get APG / Artikel Price group and artikel group
            $custom_fields = $product->getCustomFields();
            //print_r($custom_fields);die();
            $articlePriceGroup = isset($custom_fields['custom_special_conditions_artikelpreisgruppe']) ? $custom_fields['custom_special_conditions_artikelpreisgruppe'] : $custom_fields['migration_krisMigrateTry3_product_attr5'];

            $articleGroup = isset($custom_fields['custom_special_conditions_artikelgruppe']) ? $custom_fields['custom_special_conditions_artikelgruppe'] : $custom_fields['migration_krisMigrateTry3_product_attr6'];     
            
            
            
            // The current price rule id is found in cheapestPrice and if it is not there then standard price without rule id. Only get rule id from cheapestPrice because it does not give range price
            
            
            // collection of customer groups 699/043 database id with shopware ids
            /* if it is "2'' then the customer group is Palletenpries . If it is "10" then it is Fachhandel-10%
                1,2,6,10,14. 1 is Fachndel, 6 is Endkunden. take 14 as 15 Fachhandel-15%*/
            $customerGroup_ids = ['1' => '1e85675120244a0a90453f71f00a7c70','2' => '5b52c34e8ff241bd8a517506a0db6aee', '5' => '46acf8becbcb4b9bb48f86f77bf208f5',  '6' => '46acf8becbcb4b9bb48f86f77bf208f5', '10' => '909c4c431a7b411682b33061d378d2b4', '14' => 'bc6f78bf1c1047538e9f1f0acb16dc1e', '15' => 'bc6f78bf1c1047538e9f1f0acb16dc1e', '605' => '862dbc435f57498d9dc07128c0a88a1f', '610' => '37b6f1aca8be4ce5853789e89fb685ac'];
            $this->customerGroup_names = ['1' => 'Fachhandel', '2' => 'Palettenpreis', '5' => 'Fachhandel-5%', '6' => 'Endkunden', '10' => 'Fachhandel-10%', '14' => 'Fachhandel-15%', '15' => 'Fachhandel-15%', '610' => 'Endkunden-10%', '605' => 'Endkunden-5%'];
            $customerGroupId = array_search($customerGroupUUID, $customerGroup_ids);

            // check if si_cart is not available then add customer details
            $si_cart['customer'] = ['customerId' => $customerId, 'customerNumber' => $customerNumber, 'customerGroupId' => $customerGroupId, 'customerGroupName' => $this->customerGroup_names[$customerGroupId]];
            $si_cart['items'][$productId] = ['productNumber' => $productNumber, 'apg' => $articlePriceGroup, 'ag' => $articleGroup, 'qty' => $productQuantityToBeConsidered, 'price_applied' => 'default'];
            $si_cart['items'][$productId]['prices']['default'] = ['priceValue' => $currentProductPrice, 'ruleId' => $currentPriceRuleId];
            $si_cart['items'][$productId]['priceCollections'] = $si_prices;
            // Note: the distinct values of coulmn P in DB 'KundenPreisgruppen_699' are 2,5,10,15
            // So if record is found in 699 for customer number with apg then this customer will be given P value customer group 

             

            /* Connect with special price API and get the price */

            if ($responseFromApi = $this->getSpecialPriceApi($productNumber, $customerNumber, $productQuantityToBeConsidered, $articlePriceGroup)) {
                // variables in $responseFromApi : special_price , quantity, price_list_id, customer_group, P
                //print_r($responseFromApi);die();
                /* Check which price is lowest either shopware's or special database's */
                $isSpecialPricePossible = false;
                $isPriceListIdExists = false;
                $special_price_043 = false;
                $db_quantity = $responseFromApi['quantity'];
                // there are two scenarios 
                // 1st priority: if($responseFromApi['special_price']) from 043
                // check for special_price exists and less than productPrice and same customer_group of 043 and customer group
                $promotion_name = 'Special_Price_'.$customerNumber.'_'.$productNumber;
                $api_customer_group = $responseFromApi['customer_group'];
                if ($responseFromApi['special_price'] < $cheapest_price && $responseFromApi['special_price'] > 0 
                     && $api_customer_group == $customerGroupId

                    ) {

                    // now check for pricelist/customer group
                    $special_price_043 = true;
                    $priceListId = $this->customerGroup_names[$api_customer_group];//."_043";
                    $isSpecialPricePossible = true;
                    $cheapest_price = $responseFromApi['special_price'];
                    $si_cart['items'][$productId]['price_applied'] = 'db_043';
                    $si_cart['items'][$productId]['prices']['db_043'] = ['priceValue' => $cheapest_price, 'rule_id' => ''];
                    
                }

                // Now checks for db_699 only if db_043 fails
                $special_price_699 = false;
                $tagRuleName_699 = '';
                if( isset($responseFromApi['P']) && $responseFromApi['P'] > 0 ){
                    // P can only have value from (2,5,10,15)
                    $price_rebate_perc = $responseFromApi['P'];
                    // Check if 699 price is lesser than 043 price                    

                    // get customer group for P
                    $customer_group_p = $this->customerGroup_names[$price_rebate_perc];
                    //$price_rule_p = $price_list_rule_names[$customer_group_p];
                    //$price_rule_p_obj = $price_lists[$price_rule_p]['price']['c'.$currencyId]['net'];

                    //$calculated_price = $productPrice - $productPrice * $price_rebate_perc * 0.01;
                    //$calculated_price = $price_rule_p_obj;

                    //print_r($price_list_rule_names);die();

                    //Kris has created pricelists for P
                    //$priceListId_699 = 'Fachhandel-'.$price_rebate_perc."%_APG_".$articlePriceGroup;
                    $priceListId_699 = $customer_group_p;
                    //echo $price_list_rule_names[$priceListId_699];die();
                    if($price_list_rule_names[$priceListId_699]){
                        $price_699_arr = $si_prices[$price_list_rule_names[$priceListId_699]] ;
                       
                        foreach ($price_699_arr as $k_p => $price_p) {
                            if($productQuantityToBeConsidered > $price_p['quantityStart'] && ( $productQuantityToBeConsidered < $price_p['quantityEnd'] || $price_p['quantityEnd'] == '')){

                                $price_p_val = $price_p['net'];
                                break;
                            }
                        }

                        
                        $si_cart['items'][$productId]['prices']['db_699'] = ['priceValue' => $price_p_val, 'rule_id' => $price_list_rule_names[$priceListId_699]];

                        // check if this price is less than the cheapest_price                        
                        if($cheapest_price > $price_p_val && $price_p_val > 0){
                            $cheapest_price = $price_p_val;
                            $isSpecialPricePossible = true;
                            $special_price_699 = true;
                            $special_price_043 = false;
                            $si_cart['items'][$productId]['price_applied'] = 'db_699';
                            // Create the customer APG_#_P tag
                            $apg_tag_name = "APG_".$articlePriceGroup."-".$price_rebate_perc;
                            //APG_1-10
                            $criteria = new Criteria();
                            $criteria->addFilter(new EqualsFilter('name', $apg_tag_name));
                            $apg_tagRule = $this->tagRepository->search($criteria, $event->getContext());
                            //print_r($apg_tagRule);die();
                            if( $apg_tagRule->getTotal() > 0){
                                $apg_tagRule_obj = $apg_tagRule->first();
                                if (null !== $apg_tagRule_obj) {
                                    $apg_tagRuleId = $apg_tagRule_obj->getId();
                                    $customerTags = $customer->getTagIds();

                                    if (null === $customerTags || !in_array($apg_tagRuleId, $customerTags)) {
                                        
                                        $this->customerRepository->update([
                                            [
                                                'id' => $customerId,
                                                'tags' => [['id' => $apg_tagRuleId]],
                                            ],
                                        ], $event->getContext());
                                    }
                                }
                            }
                        }
                        
                        
                    }
                }else{
                    // write code to remove APG tag from customer
                    $this->deleteCustomerAPGTag($event, $articlePriceGroup);
                }

                if ($isSpecialPricePossible) {
                    /* Check Product Rule  */
                    if($special_price_043){
                        $productRuleName = 'product_'.$productNumber;

                        $criteria = new Criteria();
                        $criteria->addFilter(new EqualsFilter('name', $productRuleName));
                        $productRule = $this->ruleRepository->search($criteria, $event->getContext())->first();
                        //print_r($productRule);die();
                        if (null !== $productRule) {
                            /* Product rule already exists */
                            $productRuleId = $productRule->getId();
                        } else {
                            /* Create a new rule */
                            $productRuleUuid = Uuid::randomHex();
                            $productRuleConditionUuid = Uuid::randomHex();
                            $productRuleConditionChildrenUuid = Uuid::randomHex();
                            $productRuleConditionChildrenChildren2Children1Uuid = Uuid::randomHex();
                            $productRuleData = [
                                'id' => $productRuleUuid,
                                'name' => $productRuleName,
                                'priority' => 100,
                                'conditions' => [
                                    [
                                        'id' => $productRuleConditionUuid,
                                        'type' => 'orContainer',
                                        'ruleId' => $productRuleUuid,
                                        'position' => 0,
                                        'children' => [
                                            [
                                                'id' => $productRuleConditionChildrenUuid,
                                                'type' => 'andContainer',
                                                'ruleId' => $productRuleUuid,
                                                'parentId' => $productRuleConditionUuid,
                                                'position' => 0,
                                                'children' => [
                                                    [
                                                        'id' => $productRuleConditionChildrenChildren2Children1Uuid,
                                                        'type' => 'cartLineItem',
                                                        'ruleId' => $productRuleUuid,
                                                        'parentId' => $productRuleConditionChildrenUuid,
                                                        'position' => 1,
                                                        'value' => [
                                                            'operator' => '=',
                                                            'identifiers' => [
                                                                $productId
                                                            ]
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ];
                            $productRule = $this->ruleRepository->create([$productRuleData], $event->getContext());
                            $productRuleId = $productRuleUuid;
                        }
                    }
                    //die($productRuleId);
                    if($special_price_699){
                        $productRuleName = 'product_'.$productNumber.'_APG_'.$articlePriceGroup;
                        $criteria = new Criteria();
                        $criteria->addFilter(new EqualsFilter('name', $productRuleName));
                        $productRule = $this->ruleRepository->search($criteria, $event->getContext())->first();
                        //print_r($productRule);die();
                        if (null !== $productRule) {
                            /* Product rule already exists */
                            $productRuleId = $productRule->getId();
                        } else {
                            /* Create a new rule */
                            $productRuleUuid = Uuid::randomHex();
                            $productRuleConditionUuid = Uuid::randomHex();
                            $productRuleConditionChildrenUuid = Uuid::randomHex();
                            $productRuleConditionChildrenChildren2Children1Uuid = Uuid::randomHex();
                            $productRuleConditionChildrenChildren2Children2Uuid = Uuid::randomHex();
                            $testUuid = Uuid::randomHex();
                            $productRuleData = [
                                'id' => $productRuleUuid,
                                'name' => $productRuleName,
                                'priority' => 100,
                                'conditions' => [
                                    [
                                        'id' => $productRuleConditionUuid,
                                        'type' => 'orContainer',
                                        'ruleId' => $productRuleUuid,
                                        'position' => 0,
                                        'children' => [
                                            [
                                                'id' => $productRuleConditionChildrenUuid,
                                                'type' => 'andContainer',
                                                'ruleId' => $productRuleUuid,
                                                'parentId' => $productRuleConditionUuid,
                                                'position' => 0,
                                                'children' => [
                                                    [
                                                        'id' => $productRuleConditionChildrenChildren2Children1Uuid,
                                                        'type' => 'cartLineItem',
                                                        'ruleId' => $productRuleUuid,
                                                        'parentId' => $productRuleConditionChildrenUuid,
                                                        'position' => 1,
                                                        'value' => [
                                                            'operator' => '=',
                                                            'identifiers' => [
                                                                $productId
                                                            ]
                                                        ]
                                                    ],
                                                    
                                                    [
                                                        'id' => $productRuleConditionChildrenChildren2Children2Uuid,
                                                        'type' => 'cartLineItemCustomField',
                                                        'ruleId' => $productRuleUuid,
                                                        'parentId' => $productRuleConditionChildrenUuid,
                                                        'position' => 1,                                                        
                                                        'value' => [
                                                            'renderedField' => [ 
                                                                'name' => 'custom_special_conditions_artikelpreisgruppe',
                                                                'type' => 'text',
                                                                'config' => [
                                                                    'componentName' => 'sw-field',
                                                                    'type' => 'text',
                                                                    'customFieldType' => 'text'
                                                                ],
                                                                        
                                                                            
                                                                'active' => 1,
                                                                'customFieldSetId' => '86a13b838c83406590ced0658be5d197',
                                                                'id' => 'd691ad6f17034fd48941c02029213b74',
                                                                'customFieldSet' => ['name' => 'custom_Special_Conditions'],
                                                            ],
                                                            
                                                            'renderedFieldValue' => $articlePriceGroup, 
                                                            'operator' => '=',
                                                            'selectedField' => 'd691ad6f17034fd48941c02029213b74',
                                                            'selectedFieldSet' => '86a13b838c83406590ced0658be5d197'
                                                            
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ];
                            $productRule = $this->ruleRepository->create([$productRuleData], $event->getContext());
                            $productRuleId = $productRuleUuid;
                        }
                    }
                    //die($special_price_699);
                    if (isset($productRuleId) &&  !empty($productRuleId)) {
                        $customerRuleName = 'customer_'.$customerNumber;

                        $criteria = new Criteria();
                        $criteria->addFilter(new EqualsFilter('name', $customerRuleName));
                        $customerRule = $this->ruleRepository->search($criteria, $event->getContext())->first();
                        if (null !== $customerRule) {
                            /* Customer rule already exists */
                            $customerRuleId = $customerRule->getId();
                        } else {
                            /* Create a new customer rule */
                            $customerRuleUuid = Uuid::randomHex();
                            $customerRuleConditionUuid = Uuid::randomHex();
                            $customerRuleConditionChildrenUuid = Uuid::randomHex();
                            $customerRuleConditionChildrenChildren2Children1Uuid = Uuid::randomHex();
                            $customerRuleData = [
                                'id' => $customerRuleUuid,
                                'name' => $customerRuleName,
                                'priority' => 100,
                                'conditions' => [
                                    [
                                        'id' => $customerRuleConditionUuid,
                                        'type' => 'orContainer',
                                        'ruleId' => $customerRuleUuid,
                                        'position' => 0,
                                        'children' => [
                                            [
                                                'id' => $customerRuleConditionChildrenUuid,
                                                'type' => 'andContainer',
                                                'ruleId' => $customerRuleUuid,
                                                'parentId' => $customerRuleConditionUuid,
                                                'position' => 0,
                                                'children' => [
                                                    [
                                                        'id' => $customerRuleConditionChildrenChildren2Children1Uuid,
                                                        'type' => 'customerCustomerNumber',
                                                        'ruleId' => $customerRuleUuid,
                                                        'parentId' => $customerRuleConditionChildrenUuid,
                                                        'position' => 1,
                                                        'value' => [
                                                            'operator' => '=',
                                                            'numbers' => [
                                                                $customerNumber
                                                            ]
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ];
                            $customerRule = $this->ruleRepository->create([$customerRuleData], $event->getContext());
                            if (null !== $customerRule) {
                                $customerRuleId = $customerRuleUuid;
                            }
                        }
                        //die($customerRuleId);
                        if (isset($customerRuleId) && !empty($customerRuleId)) {
                            // this if for 043 database
                            //echo 'd='.$special_price_043;die();
                            if($special_price_043){
                                $tagRuleName = $priceListId;
                                $criteria = new Criteria();
                                $criteria->addFilter(new EqualsFilter('name', $tagRuleName));
                                $tagRule = $this->ruleRepository->search($criteria, $event->getContext())->first();
                                //var_dump($tagRule);die();
                                if (null !== $tagRule) {
                                    $tagRuleId = $tagRule->getId();
                                } else {
                                    $tagRuleUuid = Uuid::randomHex();
                                    $tagRuleConditionUuid = Uuid::randomHex();
                                    $tagRuleConditionChildrenUuid = Uuid::randomHex();
                                    $tagRuleConditionChildrenChildren2Children1Uuid = Uuid::randomHex();
                                    $tagRuleData = [
                                        'id' => $tagRuleUuid,
                                        'name' => $tagRuleName,
                                        'priority' => 100,
                                        'conditions' => [
                                            [
                                                'id' => $tagRuleConditionUuid,
                                                'type' => 'orContainer',
                                                'ruleId' => $tagRuleUuid,
                                                'position' => 0,
                                                'children' => [
                                                    [
                                                        'id' => $tagRuleConditionChildrenUuid,
                                                        'type' => 'andContainer',
                                                        'ruleId' => $tagRuleUuid,
                                                        //'parentId' => $tagRuleConditionUuid,
                                                        'parentId' => $customerRuleUuid,
                                                        'position' => 0,
                                                        'children' => [
                                                            [
                                                                'id' => $tagRuleConditionChildrenChildren2Children1Uuid,
                                                                'type' => 'customerTag',
                                                                'ruleId' => $tagRuleUuid,
                                                                'parentId' => $tagRuleConditionChildrenUuid,
                                                                'position' => 1,
                                                                'value' => [
                                                                    'operator' => '=',
                                                                    'identifiers' => [
                                                                        $tagRuleName                  
                                                                    ]
                                                                ]
                                                            ]
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ];
                                    $tagRule = $this->ruleRepository->create([$tagRuleData], $event->getContext());
                                    
                                    if (null !== $tagRule) {
                                        $tagRuleId = $tagRuleUuid;
                                    }

                                    
                                }
                            }
                            
                            //die($tagRuleId);
                            
                            // Now check for 699 database and create
                            if($special_price_699){// Stop this code
                                $tagRuleId = $apg_tagRuleId;
                                $db_quantity = 1;
                            }
                            /* ## DB699 */


                            if ( (isset($tagRuleId) && !empty($tagRuleId)) ) {
                                $personaRules = [['id' => $customerRuleId]];
                                if(isset($tagRuleId) && !empty($tagRuleId)){
                                    array_push($personaRules, ['id' => $tagRuleId]);
                                    
                                }
                                
                                $promotionUuid = Uuid::randomHex();
                                $promotionConditionId = Uuid::randomHex();
                                $promotionSalesChannelId = Uuid::randomHex();
                                $promotionDiscuountId = Uuid::randomHex();
                                $actualSalesChannelId = '6095a390d4f942ae887da4b63665c5dc'; // Fixed Static
                                $criteria = new Criteria();
                                $criteria->addFilter(new PrefixFilter('name', 'priceList_'));
                                $priceListsPromotions = $this->promotionRepository->search($criteria, $event->getContext());
                                $promotionsIds = [];
                                if (null !== $priceListsPromotions) {
                                    foreach ($priceListsPromotions as $key => $promotion) {
                                        $promotionsIds[] = $promotion->getId();
                                    }
                                }

                                $promotionData = [
                                    'id' => $promotionUuid,
                                    'name' => $promotion_name,
                                    'active' => true,
                                    'priority' => 80,
                                    'useCodes' => false,
                                    'useIndividualCodes' => false,
                                    'useSetGroups' => true,
                                    'preventCombination' => false,
                                    'setgroups' => [
                                        [
                                            'id' => $promotionConditionId,
                                            'promotionId' => $promotionUuid,
                                            'packagerKey' => 'COUNT',
                                            'sorterKey' => 'PRICE_ASC',
                                            'value' => $db_quantity,//$responseFromApi['quantity'],
                                            'setGroupRules' => [
                                                [
                                                    'id' => $productRuleId
                                                ],
                                                
                                                
                                            ]
                                        ]
                                    ],
                                    'salesChannels' => [
                                        [
                                            'id' => $promotionSalesChannelId,
                                            'promotionId' => $promotionUuid,
                                            'salesChannelId' => $actualSalesChannelId,
                                            'priority' => 1
                                        ]
                                    ],
                                    //'personaRules' => $personaRules,
                                    'personaRules' => [
                                        [
                                            'id' => $customerRuleId,
                                        ],
                                        /*[
                                            'id' => $tagRuleId,
                                        ],*/
                                        
                                    ],
                                    'cartRules' => [
                                        [
                                            'id' => $productRuleId
                                        ],
                                        


                                    ],
                                    'discounts' => [
                                        [
                                            'id' => $promotionDiscuountId,
                                            'promotionId' => $promotionUuid,
                                            'scope' => 'cart',
                                            'type' => 'fixed_unit',
                                            'value' => 0.8,//$cheapest_price,
                                            'considerAdvancedRules' => true,
                                            'sorterKey' => 'PRICE_ASC',
                                            'applierKey' => 'ALL',
                                            'usageKey' => 'ALL',
                                            'discountRules' => [
                                                [
                                                    'id' => $productRuleId
                                                ]
                                            ]
                                        ]
                                    ]
                                ];
                                if (!empty($promotionsIds)) {
                                    $promotionData['exclusionIds'] = $promotionsIds;
                                }
                                $this->deleteExistingPromotion($event, $customerNumber, $productNumber);
                                $promotion = $this->promotionRepository->create([$promotionData], $event->getContext());

                                //print_r($promotion);die();
                            }
                        }
                    }

                } else {
                    /* Delete existing promotion */
                    $this->deleteExistingPromotion($event, $customerNumber, $productNumber);
                }
            } else {
                /* Delete existing promotion */
                $this->deleteExistingPromotion($event, $customerNumber, $productNumber);
                $this->deleteCustomerAPGTag($event, $articlePriceGroup);
            }
            // store si_cart into session
            //$si_cart = array_merge($si_cart_old, $si_cart);
            //$cartId = $event->getCart()->getName();
            $cartId = $event->getCart()->getToken();
            $si_cart['cartId'] = $cartId;
            $this->session->set('si_cart', $si_cart);            

            // Check if customerGroupId is not in [1,5,6,605] then no calculation because AG price does not have scence for group above -5%

            if(!in_array($customerGroupId, ['1','5','6','605'])){
                return true;
            }

            $ag_data = [];
            // collect AG group total and not special price
            foreach($si_cart['items'] as $productId => $product ){
                for($n=1;$n<5;$n++){
                    if($product['ag'] == $n){
                        $ag_data[$n]['total'] += $product['qty'];
                        if(!isset($product['prices']['db_043']))
                            $ag_data[$n]['no_sp'] += $product['qty'];
                    }
                }
            }

            //print_r($ag_data);die();

            // now check
            // call for AG1
            $total_ag_discounts = 0;
            if($ag_data[1]['total'] >= 500)
                $total_ag_discounts += $this->recalculate_cart_price_for_ag($event, $ag_data[1], 1);

            if($ag_data[2]['total'] >= 500)
                $total_ag_discounts += $this->recalculate_cart_price_for_ag($event, $ag_data[2], 2);

            if($ag_data[3]['total'] >= 500)
                $total_ag_discounts += $this->recalculate_cart_price_for_ag($event, $ag_data[3], 3);

            if($ag_data[4]['total'] >= 50)
                $total_ag_discounts += $this->recalculate_cart_price_for_ag($event, $ag_data[4], 4);
            
            

            /*
            $cart = $event->getCart();
            $discountLineItem = $this->createDiscount($cartId);
            //$discountLineItem->setPrice(10);
            // add discount to new cart
            $cart->add($discountLineItem);*/
            $promotion_name = "AG_Promotion_".$customerNumber."-".$cartId;
            $tagName = "AG_Promotion_Tag_".$customerNumber."-".$cartId;

            if($total_ag_discounts > 0){

                // first create a tag to customer
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('name', $tagName));
                $tagRule = $this->tagRepository->search($criteria, $event->getContext())->first();
                //var_dump($tagRule);die();
                if (null !== $tagRule) {
                    $ag_tagRuleId = $tagRule->getId();
                } else {
                    $tagRuleUuid = Uuid::randomHex();
                    $tagRuleConditionUuid = Uuid::randomHex();
                    $tagRuleConditionChildrenUuid = Uuid::randomHex();
                    $tagRuleConditionChildrenChildren2Children1Uuid = Uuid::randomHex();
                    $tagRuleData = [
                        'id' => $tagRuleUuid,
                        'name' => $tagName,
                        'priority' => 100,
                        'conditions' => [
                            [
                                'id' => $tagRuleConditionUuid,
                                'type' => 'orContainer',
                                'ruleId' => $tagRuleUuid,
                                'position' => 0,
                                'children' => [
                                    [
                                        'id' => $tagRuleConditionChildrenUuid,
                                        'type' => 'andContainer',
                                        'ruleId' => $tagRuleUuid,
                                        'parentId' => $tagRuleConditionUuid,
                                        'position' => 0,
                                        'children' => [
                                            [
                                                'id' => $tagRuleConditionChildrenChildren2Children1Uuid,
                                                'type' => 'customerTag',
                                                'ruleId' => $tagRuleUuid,
                                                'parentId' => $tagRuleConditionChildrenUuid,
                                                'position' => 1,
                                                'value' => [
                                                    'operator' => '=',
                                                    'identifiers' => [
                                                        $tagName                  
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ];


                    $tagRule = $this->tagRepository->create([$tagRuleData], $event->getContext());
                    
                    if (null !== $tagRule) {
                        $ag_tagRuleId = $tagRuleUuid;
                    }
                }

                $customerTags = $customer->getTagIds();  
                

                if (null === $customerTags || !in_array($ag_tagRuleId, $customerTags)) {
                    
                    $k = $this->customerRepository->update([
                        [
                            'id' => $customerId,
                            'tags' => [['id' => $ag_tagRuleId]],
                        ],
                    ], $event->getContext());



                }

                if($ag_tagRuleId){
                    $tagRuleName = "AG_Promotion_Rule_".$customerNumber."-".$cartId;
                    $criteria = new Criteria();
                    $criteria->addFilter(new EqualsFilter('name', $tagRuleName));
                    $tagRule = $this->ruleRepository->search($criteria, $event->getContext())->first();
        
                    if (null !== $tagRule) {
                        $tagRuleId = $tagRule->getId();
                    } else {
                        $tagRuleUuid = Uuid::randomHex();
                        $tagRuleConditionUuid = Uuid::randomHex();
                        $tagRuleConditionChildrenUuid = Uuid::randomHex();
                        $tagRuleConditionChildrenChildren2Children1Uuid = Uuid::randomHex();
                        $tagRuleData = [
                            'id' => $tagRuleUuid,
                            'name' => $tagRuleName,
                            'priority' => 95,
                            'conditions' => [
                                [
                                    'id' => $tagRuleConditionUuid,
                                    'type' => 'orContainer',
                                    'ruleId' => $tagRuleUuid,
                                    'position' => 0,
                                    'children' => [
                                        [
                                            'id' => $tagRuleConditionChildrenUuid,
                                            'type' => 'andContainer',
                                            'ruleId' => $tagRuleUuid,
                                            'parentId' => $tagRuleConditionUuid,
                                            'position' => 0,
                                            'children' => [
                                                [
                                                    'id' => $tagRuleConditionChildrenChildren2Children1Uuid,
                                                    'type' => 'customerTag',
                                                    'ruleId' => $tagRuleUuid,
                                                    'parentId' => $tagRuleConditionChildrenUuid,
                                                    'position' => 1,
                                                    'value' => [
                                                        'operator' => '=',
                                                        'identifiers' => [
                                                            $ag_tagRuleId                  
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ];
                        $tagRule = $this->ruleRepository->create([$tagRuleData], $event->getContext());
                        
                        if (null !== $tagRule) {
                            $tagRuleId = $tagRuleUuid;
                        }
                }

                $promotionUuid = Uuid::randomHex();
                $promotionConditionId = Uuid::randomHex();
                $promotionSalesChannelId = Uuid::randomHex();
                $promotionDiscuountId = Uuid::randomHex();
                $actualSalesChannelId = '6095a390d4f942ae887da4b63665c5dc'; // Fixed Static
                $criteria = new Criteria();
                $criteria->addFilter(new PrefixFilter('name', 'AG_Promotion_'.$customerNumber));
                $priceListsPromotions = $this->promotionRepository->search($criteria, $event->getContext());
                $promotionsIds = [];
                if (null !== $priceListsPromotions) {
                    foreach ($priceListsPromotions as $key => $promotion) {
                        $promotionsIds[] = $promotion->getId();
                    }
                }

                $promotionData = [
                    'id' => $promotionUuid,
                    'name' => $promotion_name,
                    'active' => true,
                    'priority' => 80,
                    'useCodes' => false,
                    'useIndividualCodes' => false,
                    'useSetGroups' => false,
                    'preventCombination' => false,
                    
                    'salesChannels' => [
                        [
                            'id' => $promotionSalesChannelId,
                            'promotionId' => $promotionUuid,
                            'salesChannelId' => $actualSalesChannelId,
                            'priority' => 1
                        ]
                    ],
                    
                    'personaRules' => [
                        [
                            'id' => $tagRuleId,
                        ],
                        
                        
                    ],
                    'cartRules' => [
                        [
                            'id' => $tagRuleId,
                        ]
                    ],
                    'discounts' => [
                        [
                            'id' => $promotionDiscuountId,
                            'promotionId' => $promotionUuid,
                            'scope' => 'cart',                            
                            'type' => 'absolute',                            
                            'value' => $total_ag_discounts,
                            'considerAdvancedRules' => false,
                            /*'promotionDiscountPrices' => [
                                [
                                    'currencyId' => Defaults::CURRENCY,
                                    'discountId' => $promotionDiscuountId,
                                    'price' => '15',
                                ],
                            ],*/
                        ]
                    ]
                ];

                /*echo '<pre>';
                print_r($promotionData);die();*/
                if (!empty($promotionsIds)) {
                    $promotionData['exclusionIds'] = $promotionsIds;
                }

                // delete this cart promotion if available
                $this->deleteAGExistingPromotion($event, $customerNumber, $cartId);
                
                $promotion = $this->promotionRepository->create([$promotionData], $event->getContext());
                
            }

            }else{
                // delete this cart promotion if available

                $this->deleteAGExistingPromotion($event, $customerNumber, $cartId);
            }
            
           

        }
        
    }

   

    private function recalculate_cart_price_for_ag($event, $ag_data, $ag)
    {

        $total_ag_discounts = 0;
        $si_cart = $this->session->get('si_cart', $si_cart);
        $ag_upgraded_group = ['500' => ['1' => '5', '6' => '605'], '1000' => ['1' => '10', '5' => '10', '6' => '610', '605' => '610']];
        //print_r($si_cart);
        $customerGroupId = $si_cart['customer']['customerGroupId'];
        $customerNumber = $si_cart['customer']['customerNumber'];

        $range1 = 500;
        $range2 = 1000;
        if($ag == 4){
            $range1 = 50;
            $range2 = 100;
        }

        if($ag_data['total'] >= $range1 && $ag_data['total'] < $range2 && in_array($customerGroupId, ['1','6'])){ // only Fachhandel or Enkenden can get the discount

            foreach($si_cart['items'] as $productId => $product ){
                // this is only for AG1
                if($product['ag'] != $ag)
                    continue;
                // get the AG1 price for this product
                $ag_price_tag = $this->customerGroup_names[$ag_upgraded_group[$range1][$customerGroupId]];
                $ag_price_ruleId = $si_cart[$productId]['rules'][$ag_price_tag];
                $ag_price_collection = $product['priceCollections'][$ag_price_ruleId];          
                // get the ag price for this product     
                foreach ($ag_price_collection as $k_p => $price_p) {
                    if($product['qty'] > $price_p['quantityStart'] && ( $product['qty'] < $price_p['quantityEnd'] || $price_p['quantityEnd'] == '')){

                        $ag_price_val = $price_p['net'];
                        break;
                    }
                }
                //print_r($product['prices']);
                // Now compare with price_applied with ag_price_val
                $price_applied = $product['prices'][$product['price_applied']]['priceValue'];                    
                // check for this current product
                // there are two scenarios
                // 1. if price_applied > ag_price_val then remove special_promotion from this product and apply this ag_price_val
                if($price_applied > $ag_price_val){
                    $this->deleteExistingPromotion($event, $customerNumber, $product['productNumber']);
                    $total_ag_discounts += $product['qty'] * ($price_applied - $ag_price_val);
                }
                // 2. if price_applied <= ag_price_val then do nothing
            }
        }

        // Now for AG1 with qty >= 1000
        if($ag_data['total'] >= $range2 && in_array($customerGroupId, ['1','5','6', '605'])){ // only Fachhandel, Fachhandel-5% or Enkenden, Enkenden-5% can get the discount
            foreach($si_cart['items'] as $productId => $product ){
                // this is only for AG1
                if($product['ag'] != $ag)
                    continue;
                // get the AG1 price for this product
                $ag_price_tag = $this->customerGroup_names[$ag_upgraded_group[$range2][$customerGroupId]];
                $ag_price_ruleId = $si_cart[$productId]['rules'][$ag_price_tag];
                $ag_price_collection = $product['priceCollections'][$ag_price_ruleId];          
                // get the ag price for this product     
                foreach ($ag_price_collection as $k_p => $price_p) {
                    if($product['qty'] > $price_p['quantityStart'] && ( $product['qty'] < $price_p['quantityEnd'] || $price_p['quantityEnd'] == '')){

                        $ag_price_val = $price_p['net'];
                        break;
                    }
                }
                //print_r($product['prices']);
                // Now compare with price_applied with ag_price_val
                $price_applied = $product['prices'][$product['price_applied']]['priceValue'];                    
                // check for this current product
                // there are two scenarios
                // 1. if price_applied > ag_price_val then remove special_promotion from this product and apply this ag_price_val
                if($price_applied > $ag_price_val){
                    $this->deleteExistingPromotion($event, $customerNumber, $product['productNumber']);
                    $total_ag_discounts += $product['qty'] * ($price_applied - $ag_price_val);
                }
                // 2. if price_applied <= ag_price_val then do nothing
            }
        }
        return $total_ag_discounts;
    }

    private function lineItemQuantityInCart($event, $lineItemId, $eventName = '')
    {
        if ($eventName == 'BeforeLineItemQuantityChangedEvent') {
            return (int) 0;
        }
        if (null !== $event->getCart()->getLineItems()) {
            foreach ($event->getCart()->getLineItems() as $key => $lineItemInCart) {
                if ($lineItemInCart->getId() == $lineItemId) {
                    return (int) $lineItemInCart->getQuantity();
                }
            }
        }

        return (int) 0;
    }

    private function deleteAllAGExistingPromotion($event, $customerNumber)
    {

        $criteria = new Criteria();
        $name = "AG_Promotion_".$customerNumber."-";

        $criteria->addFilter(new ContainsFilter('name', $name));
        $promotion_context = $this->promotionRepository->search($criteria, $event->getContext());
      
     
        if($promotion_context->getTotal() > 0){
            $promotions = $promotion_context->getEntities()->getElements();
   
            foreach($promotions as $id=>$promotion){
                $this->promotionRepository->delete([
                    [
                        'id' => $id
                    ]
                ], $event->getContext());
            }

            


        }
    }

    private function deleteAGExistingPromotion($event, $customerNumber, $cartId)
    {

        $criteria = new Criteria();
        $name = "AG_Promotion_".$customerNumber."-".$cartId;

        $criteria->addFilter(new EqualsFilter('name', $name));
        $promotion_context = $this->promotionRepository->search($criteria, $event->getContext());
        if($promotion_context->getTotal() > 0){

            $promotion = $promotion_context->first();
            if (null !== $promotion) {
                $this->promotionRepository->delete([
                    [
                        'id' => $promotion->getId()
                    ]
                ], $event->getContext());
                
            }


        }

        // delete tag
        /*$criteria = new Criteria();
        $name = "AG_Promotion_Rule_".$customerNumber."-".$cartId;
        $criteria->addFilter(new EqualsFilter('name', $name));
        $tag_context = $this->ruleRepository->search($criteria, $event->getContext());
        $tag = $tag_context->first();
        if (null !== $tag) {
            $this->ruleRepository->delete([
                [
                    'id' => $tag->getId()
                ]
            ], $event->getContext());
        }

        // delete tag
        $criteria = new Criteria();
        $name = "AG_Promotion_Tag_".$customerNumber."-".$cartId;
        $criteria->addFilter(new EqualsFilter('name', $name));
        $tag_context = $this->tagRepository->search($criteria, $event->getContext());
        $tag = $tag_context->first();
        if (null !== $tag) {
            $this->tagRepository->delete([
                [
                    'id' => $tag->getId()
                ]
            ], $event->getContext());
        }  */  
        

        return true;
    }

    private function deleteExistingPromotion($event, $customerNumber, $productNumber)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'Special_Price_'.$customerNumber.'_'.$productNumber));
        $promotion_context = $this->promotionRepository->search($criteria, $event->getContext());
        if($promotion_context->getTotal() > 0){
            $promotion = $promotion_context->first();
            if (null !== $promotion) {
                $this->promotionRepository->delete([
                    [
                        'id' => $promotion->getId()
                    ]
                ], $event->getContext());
            }
        }
        
        

        return true;
    }
    private function getSpecialPriceApi($productNumber, $customerNumber, $productQuantityToBeConsidered, $articlePriceGroup)
    {
        if ($productNumber == '' || $customerNumber == '' || $productQuantityToBeConsidered == '' ) {
            return false;
        }
        $queryParams = [
            'product_number' => $productNumber,
            'customer_number' => $customerNumber,
            'quantity' => $productQuantityToBeConsidered,
            'apg' => $articlePriceGroup
        ];

        /*$queryString = http_build_query($queryParams);
        $url = self::GET_SPECIAL_PRICE_API_URL.'?'.$queryString;
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $result = curl_exec($ch); 
        $response = json_decode($result);*/

        $response = $this->getSpecialPriceData($queryParams);
        
        if ($response['status'] == 'false') {
            return false;
        }

        return ['special_price' => $response['data']['special_price'], 'quantity' => $response['data']['quantity'], 'price_list_id' => $response['data']['price_list_id'], 'customer_group' => $response['data']['c002'], 'P' => $response['data']['P']];
    }

    private function getSpecialPriceData($queryParams){

        if (!isset($queryParams['product_number']) || $queryParams['product_number'] == '' || !isset($queryParams['customer_number']) || $queryParams['customer_number'] == '' || !isset($queryParams['quantity']) || $queryParams['quantity'] == '' ) {
            return json_encode(['status' => 'false', 'message' => 'Please pass required details', 'data' => []]);
            
        }
        $productNumber = $queryParams['product_number'];
        $customerNumber = $queryParams['customer_number'];
        $quantity = $queryParams['quantity'];
        $apg = (int) $queryParams['apg'];
        $data = [];
        /*$db = mysqli_connect('phpstack-609278-2521890.cloudwaysapps.com:8082', 'gztefbjbpb', 'gztefbjbpb', 'Cd7CRHYSx9');
        $db = mysqli_connect('localhost:3306', 'gztefbjbpb', 'gztefbjbpb', 'Cd7CRHYSx9');*/
        $connection = \Shopware\Core\Kernel::getConnection();
        //$queryBuilder = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();

        // check Database KundenPreisgruppen_699 for priority 3
        $specialPriceSql = "SELECT * FROM KundenPreisgruppen_699 WHERE Kontonummer = '$customerNumber' AND APG = '$apg' LIMIT 1";
         $sqlResult = $connection->executeQuery($specialPriceSql)->fetchAll();
        
        if (is_array($sqlResult[0]) ) {
           
            $data['P'] = $sqlResult[0]['P']; // customer group
        }
        //echo $data['P'];

        // check Database Preisliste_043 for priority 4
        $specialPriceSql = "SELECT * FROM Preisliste_043 WHERE c000 ='$productNumber' AND c003 = '$customerNumber' AND c006 <= '$quantity'  LIMIT 1";
        $specialPrice = $connection->executeQuery($specialPriceSql)->fetchAll();
        if (is_array($specialPrice[0])) {
            $data['special_price'] = $specialPrice[0]['c013'];
            $data['quantity'] = $specialPrice[0]['c006'];
            $data['price_list_id'] = 'Fachhandel-'.$specialPrice[0]['c002'];   
            $data['c002'] = $specialPrice[0]['c002'];  // customer group
        }

        if(empty($data)){
            return (['status' => 'false', 'message' => 'Special price not found']);
            
        }else{
            return (['status' => 'true', 'message' => 'Special price found', 'data' => $data]);
            
        }
    }

    private function deleteCustomerAPGTag($event, $articlePriceGroup){
        
        $apg_tag_name_like = "APG_".$articlePriceGroup."-";
        $customer = $event->getSalesChannelContext()->getCustomer();
        $customerNumber = $customer->getCustomerNumber();
        $customerId = $customer->getId();
        //APG_1-10
        $criteria = new Criteria();
        $criteria->addFilter(new PrefixFilter('name', $apg_tag_name_like));
        $apg_tagRule = $this->tagRepository->search($criteria, $event->getContext());
        $unused_tags = [];
        if( $apg_tagRule->getTotal() > 0){
            $apg_tagRule_obj = $apg_tagRule->first();
            $customerTags = $customer->getTagIds();
            foreach ($apg_tagRule as $k => $tag) {
                $apg_tagRuleId = $tag->getId();
                //echo $tag->getName(); echo '<br>';
                if (in_array($apg_tagRuleId, $customerTags)) {
                    array_push($unused_tags, ['customerId' => $customerId,
                    'tagId' => $apg_tagRuleId]);
                }
            }

            

            $this->customerTagRepository->delete($unused_tags, $event->getContext());
           

            



            
        }
    }
}


