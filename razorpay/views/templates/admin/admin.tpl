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
</style>
<p><form action='{$_SERVER['REQUEST_URI']}' method='post'>
   <fieldset style='background-color:white;'>
       <legend>
           <img src='../img/admin/edit.gif' />{$modrazorpay}
       </legend>
      <table border='0' cellpadding='0' cellspacing='15' style='margin-left: 10%; border-spacing: 5px;' id='form'>

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
            <td width='130' title='{$modWebhookDescription}'><b>{$modEnableWebhookLabel}</b></td>
            <td>
               <input type='checkbox' name='ENABLE_WEBHOOK' {$modEnableWebhook} id='enabled_webhook'/>
            </td>
         </tr>
         <tr id='events'>
            <td width='' title='{$modWebhookEventDescription}'><b>{$modEnableWebhookEventsLabel}</b></td>
            <td>
               <select name='EVENTS[]' multiple>
                   <option value='{$modOrderPaidEvent}' {$modEventOrderPaidSelected}>{$modOrderPaidEvent}</option>
               </select>
            </td>
         </tr>
         <tr id='webhook_secret'>
            <td width='130' title='{$modWebhookSecretDescription}'><b>{$modWebhookSecretLabel}</b></td>
            <td>
               <input type='text' name='WEBHOOK_SECRET' value='{$modWebhookSecret}' style='width: 300px;'/>
            </td>
         </tr>
         <tr id='webhook_delay'>
            <td width='130' title='{$modWebhookWaitTimeDescription}'><b>{$modWebhookWithTimeLabel}</b></td>
            <td>
               <input type='text' name='WEBHOOK_WAIT_TIME' value='{$modWebhookWaitTime}' style='width: 300px;'/>
            </td>
         </tr>
         <tr id='webhook_url'>
            <td width='130'><b>Webhook Url</b></td>
            <td style='padding:5px 0;'>
               <span style='width:300px;font-weight: bold;' class='webhook-url' >{$webhookUrl}</span>
               <br>
               <span class='copy-to-clipboard'
                  style='background-color: #337ab7; color: white; border: none;cursor: pointer; padding: 2px 4px; text-decoration: none;'>Copy</span>
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