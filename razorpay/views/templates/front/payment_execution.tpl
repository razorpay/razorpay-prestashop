

{capture name=path}{l s='Credit Card/Debit Card/Net Banking' mod='razorpay'}{/capture}
{include file="$tpl_dir./breadcrumb.tpl"}

<h2>{l s='Order summary' mod='razorpay'}</h2>

{assign var='current_step' value='payment'}

<h3>{l s='You have chosen to pay by Credit/Debit card/Netbanking - Razorpay.' mod='razorpay'}</h3>
<br/>
<h3> Click the Pay Now button below to process the payment.</h3>
<br/>
<h4>
<script src="{$checkout_url}"></script>
<script>
  var razorpayData = {$json};
</script>
<form name='razorpayform' action="{$return_url}" method="POST">
  <input type="hidden" name="merchant_order_id" value="{$cart_id}">
  <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
</form>
<script>
  razorpayData.backdropClose = false;
  razorpayData.handler = function(payment){
    document.getElementById('razorpay_payment_id').value =
      payment.razorpay_payment_id;
    document.razorpayform.submit();
  };
  var razorpayCheckout = new Razorpay(razorpayData);
  razorpayCheckout.open();
</script>
<p>
  <button id="btn-razorpay" onclick="razorpayCheckout.open();">Pay Now</button>
</p>
</h4>
<br/><br/>
<p class="cart_navigation">
    <a href="{$link->getPageLink('order', true, NULL, "step=3")}" class="button_large">
        {l s='Other payment methods' mod='checkout'}
    </a>
</p>
