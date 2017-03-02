{capture name=path}{l s='Card/Net Banking/Wallet' mod='razorpay'}{/capture}
{include file="$tpl_dir./breadcrumb.tpl"}

<h2>{l s='Order summary' mod='razorpay'}</h2>

{assign var='current_step' value='payment'}

<h3>{l s='You have chosen to pay by Card/Net Banking/Wallet - Razorpay.' mod='razorpay'}</h3>
<br/>
<h3> Click the Pay Now button below to process the payment.</h3>
<br/>
<script src="{$checkout_url}"></script>
<script>
  var razorpayData = {$json}; 
</script>
<form name='razorpayform' id="razorpay-form" action="{$return_url}" method="POST">
  <input type="hidden" name="merchant_order_id" value="{$cart_id}">
  <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
  <input type="hidden" name="razorpay_signature" id="razorpay_signature">
</form>
<script>
  razorpayData.handler = function(payment){
    document.getElementById('razorpay_payment_id').value = payment.razorpay_payment_id;
    document.getElementById('razorpay_signature').value = payment.razorpay_signature;
    document.getElementById('razorpay-form').submit();
  };
  var razorpayCheckout = new Razorpay(razorpayData);
  razorpayCheckout.open();
</script>
<p class='cart_navigation' style="margin:15px;">
  <a class="button_large" id="btn-razorpay" onclick="razorpayCheckout.open();">Pay Now</a>
  <a href="{$link->getPageLink('order', true, NULL, "step=3")}" class="button_large">
        {l s='Other payment methods' mod='razorpay'}
    </a>
</p>
