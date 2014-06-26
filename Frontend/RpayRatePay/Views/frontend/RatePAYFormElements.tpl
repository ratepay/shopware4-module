{if $ratepayValidateIsB2B == 'true'}
    {if $ratepayValidateUST != 'true'}
        <p class='none'>
            <label for='ratepay_ustid'>{s namespace=RatePAY name=vatId}Umsatzsteuer{/s}:</label>
            <input id='ratepay_ustid' class='text' type="text">
        </p>
    {/if}
    {if $ratepayValidateCompanyName != 'true'}
        <p class='none'>
            <label for='ratepay_company'>{s namespace=RatePAY name=company}Firmenname{/s}:</label>
            <input id='ratepay_company' class='text' type="text">
        </p>
    {/if}
{/if}
{if $ratepayValidateTelephoneNumber != 'true'}
    <p class='none'>
        <label for='ratepay_phone'>{s namespace=RatePAY name=phone}Telefonnummer{/s}:</label>
        <input id='ratepay_phone' class='text' type="text">
    </p>
{/if}

    <div class="none" {if $ratepayValidateIsBirthdayValid == 'true' && $ratepayValidateisAgeValid == 'true' || $sUserData.billingaddress.birthday != '0000-00-00'}style="display: none;"{/if}>
        <label for="ratepay_birthday" class="normal">{s namespace=RatePAY name=birthday}Geburtsdatum{/s}:</label>
        <p>{s namespace=RatePAY name=dob_info}Sie müssen mindestens 18 Jahre alt sein, um mit RatePay bezahlen zu können.{/s}</p>
        <select id="ratepay_birthday">
            <option value="">{s namespace=RatePAY name=dob_day}Tag{/s}</option>
            {section name="birthdate" start=1 loop=32 step=1}
                <option value="{$smarty.section.birthdate.index}"
                        {if $smarty.section.birthdate.index eq $sUserData.billingaddress.birthday|date_format:"%e"}selected{/if}>{$smarty.section.birthdate.index}</option>
            {/section}
        </select>
        <select id="ratepay_birthmonth">
            <option value="">{s namespace=RatePAY name=dob_month}Monat{/s}</option>
            {section name="birthmonth" start=1 loop=13 step=1}
                <option value="{$smarty.section.birthmonth.index}"
                        {if $smarty.section.birthmonth.index eq $sUserData.billingaddress.birthday|date_format:"%m"}selected{/if}>{$smarty.section.birthmonth.index}</option>
            {/section}
        </select>
        <select id="ratepay_birthyear">
            <option value="">{s namespace=RatePAY name=dob_year}Jahr{/s}</option>
            {section name="birthyear" start=$smarty.now|date_format:"%Y"-18 loop=2000 max=90 step=-1}
                <option value="{$smarty.section.birthyear.index}"
                        {if $smarty.section.birthyear.index eq $sUserData.billingaddress.birthday|date_format:"%Y"}selected{/if}>{$smarty.section.birthyear.index}</option>
            {/section}
        </select>
    </div>


<script language='javascript'>
    $(document).ready(function () {

        /* returns correct YYYY-MM-dd dob */
        Date.prototype.yyyymmdd = function () {
            var yyyy = this.getFullYear().toString();
            var mm = (this.getMonth() + 1).toString();
            var dd = this.getDate().toString();
            return yyyy + '-' + (mm[1] ? mm : "0" + mm[0]) + '-' + (dd[1] ? dd : "0" + dd[0]);
        };

        /* returns age */
        function getAge(dateString) {
            var today = new Date();
            var birthDate = new Date(dateString);
            var age = today.getFullYear() - birthDate.getFullYear();
            var m = today.getMonth() - birthDate.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            return age;
        }

        $("#basketButton").click(function () {
            var requestParams = 'userid=' + "{$sUserData.billingaddress.userID}";
            var dob = false;
            var userUpdate = true;
            var error = false;
            var errorMessage = '{s namespace=RatePAY name=invaliddata}Bitte vervollst&auml;ndigen Sie die Daten.{/s}';

            /* handle all normal inputs */
            $('input[id^="ratepay_"]').each(function () {
                requestParams += '&' + $(this).attr('id') + '=' + $(this).val();
                if ($(this).val() == '' || $(this).val() == '0000-00-00') {
                    error = true;
                }

                /* validate sepa direct debit - no error if no blz is net @toDo: fix for international direct debits */

                if ($(this).attr('id') == 'ratepay_debit_bankcode' && !$(":input#ratepay_debit_accountnumber").val().match(/^\d+$/)) {
                    error = false;
                    userUpdate = false;

                }

            });

            /* dob validation */
            if ($('#ratepay_birthyear').val() != '' && $('#ratepay_birthmonth').val() != '' && $('#ratepay_birthday').val() != '') {
                dob = new Date($('#ratepay_birthyear').val() + " " + $('#ratepay_birthmonth').val() + " " + $('#ratepay_birthday').val());

                /* validate age */
                if (getAge(dob) < 18) {
                    error = true;
                    userUpdate = false;
                    errorMessage = '{s namespace=RatePAY name=dobtooyoung}Für eine Bezahlung mit RatePay müssen sie mindestens 18 Jahre alt sein.{/s}'
                }

                requestParams += '&ratepay_dob=' + dob.yyyymmdd();
            } else {
                error = true;
                userUpdate = false;
            }

            /* error handler */
            if (error) {
                $("#ratepay_error").text(errorMessage);
                $("#ratepay_error").parent().show();
                $('html, body').animate({
                    scrollTop: $("#ratepay_error").offset().top - 100
                }, 1000);
                return false;
            } else {
                $("#ratepay_error").parent().hide();
            }

            /* update user */
            if (userUpdate) {
                $.ajax({
                    type: "POST",
                    async: false,

                    {if $smarty.server.HTTPS eq '' || $smarty.server.HTTPS eq 'off'}
                    url: "{url controller='RpayRatepay' action='saveUserData'}",
                    {else}
                    url: "{url controller='RpayRatepay' action='saveUserData' forceSecure}",
                    {/if}


                    data: requestParams
                }).done(function (msg) {
                            if (msg == 'OK') {
                                console.log('{s namespace=RatePAY name=updateUserSuccess}UserDaten erfolgreich aktualisiert.{/s}');
                            } else {
                                console.log('{s namespace=RatePAY name=updateUserSuccess}Fehler beim Aktualisieren der UserDaten. Return: {/s}' + msg);
                            }
                        });
            }


        });
    });
</script>
