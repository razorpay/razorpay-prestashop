$(document).ready(function () {
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
  submitButton.insertAdjacentElement('beforebegin', newSubmitButton);

  var intervalId = null;

  var renderPaymentFrame =  function(data) {
    var defaults = window.razorpay_checkout_vars;
    options = {
      name: defaults.name,
      amount: data.amount,
      description: defaults.description,
      name: defaults.name,
      key: defaults.key,
      currency: data.currency,
      prefill: {
        name: data.customer_name,
        email: data.customer_email,
        contact: data.contact_number,
      },
      order_id: data.rzp_order_id,
      notes: {
        prestashop_order_id: '',
        prestashop_cart_id: defaults.cart_id,
      },
      _: {
        integration: 'prestashop',
        integration_version: defaults.module_version,
        integration_parent_version: defaults.ps_version
      },
      callback_url: defaults.action_controller,
      handler: function(obj) {
        clearInterval(intervalId);

        // Find the payment form with the correct action
        var form = document.querySelector(
          'form[id=payment-form][action$="'+ defaults.action_controller + '"]'
        );

        //set razorpay payment id
        let url = new URL(defaults.action_controller);
        url.searchParams.set("razorpay_payment_id", obj.razorpay_payment_id);

        form.setAttribute(
          'action',
          url.href
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
  };

  // Pay button gets clicked
  newSubmitButton.addEventListener('click', function(event) {

    let defaults = window.razorpay_checkout_vars;

    $.post(defaults.order_controller, '[]').then(function (response) {
      if (response.success) {
        renderPaymentFrame(response);
      }
    });
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
