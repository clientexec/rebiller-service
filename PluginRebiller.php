<?php
require_once 'modules/admin/models/ServicePlugin.php';
/**
* @package Plugins
*/
class PluginRebiller extends ServicePlugin
{
    protected $featureSet = 'billing';
    public $hasPendingItems = true;
    public $permission = 'billing_view';

    function getVariables()
    {
        $variables = array(
            lang('Plugin Name')   => array(
                'type'          => 'hidden',
                'description'   => '',
                'value'         => lang('Invoice Reminder'),
            ),
            lang('Enabled')       => array(
                'type'          => 'yesno',
                'description'   => lang('When enabled, late invoice reminders will be sent out to customers. This service should only run once per day to avoid sending reminders twice in the same day.'),
                'value'         => '0',
            ),
            lang('Days to trigger reminder')       => array(
                'type'          => 'text',
                'description'   => lang('<b>For late invoice remainder</b>: Enter the number of days after the due date to send a late invoice reminder.  You may enter more than one day by seperating the numbers with a comma.  <strong><i>Note: A number followed by a + sign indicates to send for all days greater than the previous number or use * to send reminders each day.</i></strong><br><br><b>For upcoming invoice reminder</b>: Enter the number of days before the due date to send an upcoming invoice reminder. You may enter more than one day by seperating the numbers by commas: these numbers must start with a - sign (negative numbers). <strong><i>Note: this only works if the invoice is already generated</i></strong>.<br><br><b>Example</b>: -10,-5,-1,1,5,10+ would send on the tenth days before the due date, five days before, one day before, one day late, five days late and ten or more days late'),
                'value'         => '-10,-5,-1,1,5,10+',
            ),
            lang('Run schedule - Minute')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '0',
                'helpid'        => '8',
            ),
            lang('Run schedule - Hour')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '0',
            ),
            lang('Run schedule - Day')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '*',
            ),
            lang('Run schedule - Month')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '*',
            ),
            lang('Run schedule - Day of the week')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number in range 0-6 (0 is Sunday) or a 3 letter shortcut (e.g. sun)'),
                'value'         => '*',
            ),
        );

        return $variables;
    }

    function execute()
    {
        require_once 'modules/billing/models/BillingGateway.php';
        $messages = array();
        $numReminders = 0;
        $arrDays = explode(',', $this->settings->get('plugin_rebiller_Days to trigger reminder'));

        for($i=0; $i < sizeof($arrDays); $i++) {
            if (mb_substr($arrDays[$i],strlen($arrDays[$i]) - 1, 1) == '+') {
                $val = mb_substr($arrDays[$i],0,strlen($arrDays[$i]) - 1);
                if (!isset($gtNum) || $gtNum > $val)
                    $gtNum = $val;
            }
        }

        $query = "SELECT i.id, (TO_DAYS(NOW()) - TO_DAYS(i.`billdate`)) AS days "
                ."FROM `invoice` i, `users` u "
                ."WHERE (i.`status`='0' OR i.`status`='5') AND u.`id`=i.`customerid` AND u.`status`='1' AND i.`subscription_id` = '' "
                ."ORDER BY i.`billdate`";
        $result = $this->db->query($query);
        while ($row = $result->fetch()) {

            //Invoice reminder will now send the invoice only if:
            //- The invoice has an entry that applies to nothing.
            //- The invoice has an entry that applies to an active or pending, or suspended package.
            $sendInvoice = true;
            $packages = array();
            $reviewPackages = true;
            $query2 = "SELECT DISTINCT `appliestoid` "
                    ."FROM `invoiceentry` "
                    ."WHERE `invoiceid` = ? ";
            $result2 = $this->db->query($query2, $row['id']);
            while ($row2 = $result2->fetch()) {
                if($row2['appliestoid'] == 0){
                    $reviewPackages = false;
                    break;
                }else{
                    $packages[] = $row2['appliestoid'];
                }
            }
            if($reviewPackages && count($packages)){
                $query3 = "SELECT COUNT(*) "
                        ."FROM `domains` "
                        ."WHERE `id` IN(".implode(', ', $packages).") AND `status` IN(".PACKAGE_STATUS_PENDING.", ".PACKAGE_STATUS_ACTIVE.", ".PACKAGE_STATUS_SUSPENDED.") ";
                $result3 = $this->db->query($query3);
                list($emailPackages) = $result3->fetch();
                if($emailPackages == 0){
                    $sendInvoice = false;
                }
            }

            if ($sendInvoice && (in_array($row['days'], $arrDays) || in_array('*',$arrDays) || (isset($gtNum) && $row['days'] >= $gtNum))) {
                $billingGateway = new BillingGateway($this->user);
                if($row['days'] > 0){
                    $billingGateway->sendInvoiceEmail($row['id'],"Overdue Invoice Template");
                }else{
                    $billingGateway->sendInvoiceEmail($row['id'],"Invoice Template");
                }
                $numReminders++;
            }
        }
        array_unshift($messages, $this->user->lang('%s invoice reminders were sent', $numReminders));
        return $messages;
    }

    function pendingItems()
    {

        include_once 'modules/billing/models/Currency.php';
        $currency = new Currency($this->user);
        // Select all customers that have an invoice that needs generation
        $query = "SELECT i.`id`,i.`customerid`, i.`amount`, i.`balance_due`, (TO_DAYS(NOW()) - TO_DAYS(i.`billdate`)) AS days "
                ."FROM `invoice` i, `users` u "
                ."WHERE (i.`status`='0' OR i.`status`='5') AND u.`id`=i.`customerid` AND u.`status`='1' AND TO_DAYS(NOW()) - TO_DAYS(i.`billdate`) > 0 AND i.`subscription_id` = '' "
                ."ORDER BY i.`billdate`";
        $result = $this->db->query($query);
        $returnArray = array();
        $returnArray['data'] = array();
        while ($row = $result->fetch()) {
            $user = new User($row['customerid']);
            $tmpInfo = array();
            $tmpInfo['customer'] = '<a href="index.php?fuse=clients&controller=userprofile&view=profilecontact&frmClientID=' . $user->getId() . '">' . $user->getFullName() . '</a>';
            $tmpInfo['invoice_number'] = '<a href="index.php?controller=invoice&fuse=billing&frmClientID=' . $user->getId() . '&view=invoice&invoiceid=' . $row['id'] . ' ">' . $row['id'] . '</a>';
            $tmpInfo['amount'] = $currency->format($this->settings->get('Default Currency'), $row['amount'], true);
            $tmpInfo['balance_due'] = $currency->format($this->settings->get('Default Currency'), $row['balance_due'], true);
            $tmpInfo['days'] = $row['days'];
            $returnArray['data'][] = $tmpInfo;
        }
        $returnArray["totalcount"] = count($returnArray['data']);
        $returnArray['headers'] = array (
            $this->user->lang('Customer'),
            $this->user->lang('Invoice Number'),
            $this->user->lang('Amount'),
            $this->user->lang('Balance Due'),
            $this->user->lang('Days Overdue'),
        );
        return $returnArray;
    }

    function output() { }

    function dashboard()
    {
        $query = "SELECT COUNT(*) AS overdue "
                ."FROM `invoice` i, `users` u "
                ."WHERE (i.`status`='0' OR i.`status`='5') AND u.`id`=i.`customerid` AND u.`status`='1' AND TO_DAYS(NOW()) - TO_DAYS(i.`billdate`) > 0 AND i.`subscription_id` = '' ";
        $result = $this->db->query($query);
        $row = $result->fetch();
        if (!$row) {
            $row['overdue'] = 0;
        }
        return $this->user->lang('Number of invoices overdue: %d', $row['overdue']);
    }
}
?>
