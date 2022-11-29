<?php

namespace Drupal\ilr_outreach_discount_api;

use Drupal\salesforce\Rest\RestClient;
use Drupal\salesforce\SelectQuery;

class IlrOutreachDiscountManager {

  /**
  * The Salesforce client.
  *
  * @var \Drupal\salesforce\Rest\RestClient
  */
  protected $client;

  /**
   * Constructs a new IlrOutreachDiscountManager object.
   *
   * @param \Drupal\salesforce\Rest\RestClient $client
   *   The Salesforce client.
   */
  public function __construct(RestClient $client) {
    $this->client = $client;
  }

  /**
   * Get eligible class discounts directly from Salesforce.
   *
   * From Dan E. on May 12th, 2022:
   * 1) Query the rule that matches the class and discount IDs.
   * 2) If it’s marked as “Eligible” and the registration date is between the
   *    discount start and end dates, it is eligible
   * 3) If there are NO Discount_Class rules that match and the discount is
   *    marked as “Universal” and the registration date is between the discount
   *    start and end dates, it’s eligible
   * 4) Otherwise, it’s not eligible
   *
   * @param string $discount_code
   *   The discount code.
   * @param string|null $class_sf_id
   *   The salesforce id of the class.
   * @param string|null $error
   *   An error message passed by reference.
   *
   * @return \Drupal\ilr_outreach_discount_api\IlrOutreachDiscount|FALSE
   *   Discount info if class is eligible for this discount. FALSE if class is
   *   not eligible.
   *
   * @todo Throw errors rather than use the referenced $error var.
   */
  public function getEligibleDiscount(string $discount_code, string $class_sf_id = null, string &$error = NULL) {
    // Get the discount code, along with any discount class 'rule' records.
    $soql_query = new SelectQuery('EXECED_Discount_Code__c');
    $soql_query->fields = [
      'Id',
      'Name',
      'Discount_Amount__c',
      'Discount_Percent__c',
      'Discount_Type__c',
      'Discount_Start_Date__c',
      'Discount_End_Date__c',
      'Universal__c',
      "(SELECT Id, Name, Class__c, Eligible__c FROM Discount_Classes__r)",
    ];
    $soql_query->addCondition('Name', "'" . addslashes($discount_code) . "'");
    $soql_query->addCondition('Discount_Type__c', ['Individual_Percentage', 'Individual_Amount']);
    $results = $this->client->query($soql_query);

    if ($results->size()) {
      $discount_records = $results->records();

      /** @var \Drupal\salesforce\SObject $discount_code_object */
      $discount_code_object = reset($discount_records);
    }
    else {
      $error = "Discount code '{$discount_code}' is invalid.";
      return FALSE;
    }

    $discount_start_date = new \DateTime($discount_code_object->field('Discount_Start_Date__c'));
    $discount_end_date = new \DateTime($discount_code_object->field('Discount_End_Date__c'));
    $now_date = new \DateTime('now');
    $rules_for_class = [];

    // Gather the rules for only this class. We collect all of the rules,
    // including those for other classes, so the orderprocessor can see them.
    if (($rules = $discount_code_object->field('Discount_Classes__r')) && isset($rules['records'])) {
      foreach ($rules['records'] as $rule) {
        if ($rule['Class__c'] === $class_sf_id) {
          $rules_for_class[] = $rule;
        }
      }
    }

    // If there is a discount start date and it's in the future, not eligible.
    if ($discount_code_object->field('Discount_Start_Date__c') && $discount_start_date > $now_date) {
      $error = "Discount code '{$discount_code}' is currently ineligible.";
      return FALSE;
    }
    // If there is a discount end date and it's in the past, not eligible. We
    // add a day to the end date since it is set to midnight (00:00) and all
    // times on that day would be considered later.
    elseif ($discount_code_object->field('Discount_End_Date__c') && $discount_end_date->add(new \DateInterval('P1D')) < $now_date) {
      $error = "Discount '{$discount_code}' is no longer eligible.";
      return FALSE;
    }
    // If there are 'rules' for this discount/class combo.
    elseif ($rules_for_class) {
      foreach ($rules_for_class as $rule) {
        // If any rule for this class is not eligible, this discount is not eligible.
        if ($rule['Eligible__c'] === FALSE) {
          $error = "Discount '{$discount_code}' is not eligible for this class.";
          return FALSE;
        }
      }
    }
    // There are no rules for this discount/class combo.
    elseif (!$discount_code_object->field('Universal__c')) {
      $error = "Discount code '{$discount_code}' is not applicable.";
      return FALSE;
    }

    $eligible_discount = new IlrOutreachDiscount;
    $eligible_discount->code = $discount_code;
    $eligible_discount->sfid = $discount_code_object->id();
    $eligible_discount->universal = $discount_code_object->field('Universal__c');

    if ($discount_code_object->field('Discount_Type__c') === 'Individual_Percentage') {
      $eligible_discount->type = 'percentage';
      $eligible_discount->value =  $discount_code_object->field('Discount_Percent__c') / -100;
    }
    else {
      $eligible_discount->type = 'amount';
      $eligible_discount->value = $discount_code_object->field('Discount_Amount__c') * -1;
    }

    // Only store 'eligible' rules for this discount code. `appliesTo`
    // should end up as an array of Salesforce class object IDs for which
    // this non-universal code applies.
    if ($discount_classes = $discount_code_object->field('Discount_Classes__r')) {
      foreach ($discount_classes['records'] as $discount_class) {
        if ($discount_class['Eligible__c']) {
          $eligible_discount->appliesTo[] = $discount_class['Class__c'];
        }
        else {
          $eligible_discount->excludes[] = $discount_class['Class__c'];
        }
      }
    }

    return $eligible_discount;
  }

}
