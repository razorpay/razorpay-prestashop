<br />
<br />
<style>
   #form input[type=text], select {
     width: 250px !important;
     margin: 8px 0;
     display: inline-block;
     border: 1px solid #ccc;
     border-radius: 4px;
     box-sizing: border-box;
     background-color: #f5f8f9 !important;
   }
   #form input[type=text]{
     height: 30px !important;
   }
   a:link{
      color: blue;
   }
   a:visited{
      color: blue;
   }
</style>
<p><form action='{$smarty.server.REQUEST_URI}' method='post'>
   <fieldset style='background-color:white;'>
       <legend>
           <img src='../img/admin/edit.gif' />{$modrazorpay}
       </legend>
      <table border='0' cellpadding='0' cellspacing='15' style='margin-left: 10%; border-spacing: 5px;' id='form'>

         <tr>
            First <a href="https://easy.razorpay.com/onboarding?recommended_product=payment_gateway&source=prestashop" target="_blank">signup</a> for a 
            Razorpay account or <a href="https://dashboard.razorpay.com/signin?screen=sign_in&source=prestashop" target="_blank">login</a> if you have an existing account.
         </tr>
         <tr>
            <td width='130'><b>{$modClientLabelKeyId}</b></td>
            </br>
            <td>
               <input type='text' name='KEY_ID' value='{$modClientValueKeyId}' style='width: 300px;' />
            </td>
         </tr>
         <tr>
            <td width='130'><b>{$modClientLabelKeySecret}</b></td>
            <td>
               <input type='text' name='KEY_SECRET' value='{$modClientValueKeySecret}' style='width: 300px;' />
            </td>
         </tr>
         <tr>
            <td width='130'><b>Payment Action</b></td>
            <td>
               <select name='PAYMENT_ACTION'>
                   <option value='{$modPayActionAuthorize}' {$modPayActionAuthorizeSelected} >{$modPayActionAuthorizeLabel}</option>
                   <option value='{$modPayActionCapture}' {$modPayActionCaptureSelected} >{$modPayActionCaptureLabel}</option>
               </select>
            </td>
         </tr>
         <tr>
            <td colspan='2' align='center'>
               <input class='button' name='btnSubmit' value='{$modUpdateSettings}' type='submit' />
            </td>
         </tr>
      </table>
   </fieldset>
   </form>
</p>
<br />
<script type='text/javascript'>
   $(function() {
       $('.copy-to-clipboard').click(function() {
           var copyText = document.createElement('input');
           copyText.type = 'text';
           document.body.appendChild(copyText);
           copyText.style = 'display: inline; width: 1px;';
           copyText.value = $('.webhook-url').text();
           copyText.focus();
           copyText.select();
           document.execCommand('Copy');
           copyText.remove();
           $('.copy-to-clipboard').text('Webhook url copied to clipboard.');
       });

       $('#enabled_webhook').change(function () {

           if (this.checked)
           {
               $('#events').fadeIn('slow');
               $('#webhook_secret').fadeIn('slow');
               $('#webhook_delay').fadeIn('slow');
               $('#webhook_url').fadeIn('slow');
           }else {
               $('#events').toggle();
               $('#webhook_secret').toggle();
               $('#webhook_delay').toggle();
               $('#webhook_url').toggle();
           }

       }).change();
   });
</script>
