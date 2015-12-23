

{capture name=path}{l s='Credit Card/Debit Card/Net Banking' mod='razorpay'}{/capture}
{include file="$tpl_dir./breadcrumb.tpl"}

<h2>{l s='Order summary' mod='razorpay'}</h2>

{assign var='current_step' value='payment'}

<h3>{l s='You have chosen to pay by Credit/Debit card/Netbanking - Razorpay.' mod='razorpay'}</h3>
<br/>
<h3> Click the Pay Now button below to process the payment.</h3>
<br/>
<h4>
<form action="{$return_url}" method="POST">
  {literal}
  <script
    src="{$CheckoutUrl}"
    data-key="{$key_id}"
    data-amount="{$amount}"
    data-currency="{$currency_code}"
    data-name="{$name}"
    data-description="Order # {$cart_order_id}"
    data-netbanking="true"
    data-prefill.name="{$card_holder_name}"
    data-prefill.email="{$email}"
    data-prefill.contact="{$phone}"
    data-notes.prestashop_order_id="{$cart_order_id}">
  </script>
  {/literal}
  <input type="hidden" name="merchant_order_id" value="{$cart_order_id}"/> 
</form>
</h4>
<br/><br/>
<p class="cart_navigation">
    <a href="{$link->getPageLink('order', true, NULL, "step=3")}" class="button_large">
        {l s='Other payment methods' mod='checkout'}
    </a>
</p>
