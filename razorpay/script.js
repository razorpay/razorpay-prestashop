document.addEventListener('DOMContentLoaded', function(event) {
  var conditionsForm = document.getElementById('conditions-to-approve');
  // This is a <div> and not the button.
  var submitButton = document.getElementById('payment-confirmation');
  var newSubmitButton = document.createElement('button');
  var baseClass = 'btn btn-primary center-block ';
  newSubmitButton.id = 'razorpay-pay-button';

  if (!submitButton) {
    return;
  }

  var style =
    '\
    <style>\
    #razorpay-pay-button.shown{\
      display: block;\
    }\
    #razorpay-pay-button.not-shown{\
      display: none;\
    }\
    #razorpay-pay-button.shown+#payment-confirmation {\
      display:none !important;\
    }\
    </style>';

  newSubmitButton.innerHTML = 'Pay' + style;
  newSubmitButton.className = baseClass + 'not-shown';
  conditionsForm.insertAdjacentElement('afterend', newSubmitButton);

  var intervalId = null;

  var contactNumber = (function() {
    for (var i in prestashop.customer.addresses) {
      var address = prestashop.customer.addresses[i];
      if (address.phone !== '') {
        return address.phone;
      }
      if (address.phone_mobile !== '') {
        return address.phone_mobile;
      }
    }
    return '';
  })();

  // Pay button gets clicked
  newSubmitButton.addEventListener('click', function(event) {
    var defaults = window.razorpay_checkout_vars;
    var customer = prestashop.customer;
    options = {
      name: prestashop.shop.name,
      amount: defaults.amount,
      description: defaults.description,
      name: defaults.name,
      key: defaults.key,
      currency: prestashop.currency.iso_code,
      prefill: {
        name: customer.firstname + ' ' + customer.lastname,
        email: customer.email,
        contact: contactNumber,
      },
      order_id: defaults.rzp_order_id,
      notes: {
        prestashop_order_id: '',
        prestashop_cart_id: defaults.cart_id,
      },
      _: {
        integration: 'prestashop',
        integration_version: defaults.module_version,
        integration_parent_version: defaults.ps_version
      },
      handler: function(obj) {
        clearInterval(intervalId);

        // Find the payment form with the correct action
        var form = document.querySelector(
          'form[id=payment-form][action$="razorpay/validation"]'
        );

        var action = form.getAttribute('action');

        form.setAttribute(
          'action',
          action + '?razorpay_payment_id=' + obj.razorpay_payment_id
        );

        let razorpay_signature = document.createElement("INPUT");
        Object.assign(razorpay_signature, {
          type: "hidden",
          name: "razorpay_signature",
          value: obj.razorpay_signature
        });

        form.appendChild(razorpay_signature);

        submitButton.getElementsByTagName('button')[0].click();
      },
    };
    var checkout = new Razorpay(options);
    checkout.open();
  });

  var parent = document.querySelector('#checkout-payment-step');

  parent.addEventListener(
    'change',
    function(e) {
      var target = e.target;
      var type = target.type;

      // We switch the buttons whenever a radio button (payment method)
      // or a checkbox (conditions) is changed
      if (
        (target.getAttribute('data-module-name') && type === 'radio') ||
        type === 'checkbox'
      ) {
        var selected = this.querySelector('input[data-module-name="razorpay"]')
          .checked;

        if (selected) {
          newSubmitButton.className = baseClass + 'shown';
        } else {
          newSubmitButton.className = baseClass + 'not-shown';
        }

        // This returns the first condition that is not checked
        // and works as a truthy value
        newSubmitButton.disabled = !!document.querySelector(
          'input[name^=conditions_to_approve]:not(:checked)'
        );
      }
    },
    true
  );
});
