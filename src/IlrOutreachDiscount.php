<?php

namespace Drupal\ilr_outreach_discount_api;

/**
 * An ILR Outreach discount.
 *
 * These are generally stored in the order data to represent applicable
 * discounts for class items in the order.
 */
class IlrOutreachDiscount {

  /**
   * The discount code.
   *
   * @var string
   */
  public $code;

  /**
   * The discount code Salesforce ID.
   *
   * @var string
   */
  public $sfid;

  /**
   * The discount type ('percentage' or 'amount').
   *
   * @var string
   */
  public $type;

  /**
   * The discount value.
   *
   * @var float
   */
  public $value;

  /**
   * If the discount applies to all items or only items via rules.
   *
   * @var boolean
   */
  public $universal;

  /**
   * The Salesforce Class IDs the discount applies to.
   *
   * @var array
   */
  public $appliesTo = [];

  /**
   * The Salesforce Class IDs the discount cannot apply to.
   *
   * @var array
   */
  public $excludes = [];

}
