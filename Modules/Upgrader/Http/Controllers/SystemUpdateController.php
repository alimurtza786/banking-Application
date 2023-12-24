<?php

/**
 * @package SystemUpdateController
 * @author TechVillage <support@techvill.org>
 * @contributor Md. Mostafijur Rahman <[mostafijur.techvill@gmail.com]>
 * @created 01-03-2023
 */

namespace Modules\Upgrader\Http\Controllers;

use Modules\Upgrader\Entities\UpgradeManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use App\Http\Controllers\Controller;
use App\Http\Helpers\Common;
use App\libraries\PhpInfo;
use Modules\Addons\Entities\Envato;
use Illuminate\Http\Request;
use ZipArchive;

class SystemUpdateController extends Controller
{
    protected $helper;

    public function __construct()
    {
        $this->helper = new Common();
    }
    /**
     * Display the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function upgrade(Request $request)
    {
        if ($request->isMethod('get')) {

            // Waiting for upgrade
            if ($request->has('waiting')) {
                $this->waiting();
                return;
            }

            // Process the upgrade
            if ($request->has('process')) {
                $this->process();
                return;
            }

            $data['menu'] = 'system-update';
            $data['applicationVersion'] = config('paymoney.version');
            return view('upgrader::update', $data);
        }

        // Store the upgrade zip file
        if ($request->isMethod('post')) {
            $validator = Validator::make($request->all(), [
                'attachment' => 'required|mimes:zip,rar,7zip',
                'purchaseCode' => 'required',
            ]);


            if ($validator->fails()) {
                $message = $this->configuration();

                $this->helper->one_time_message('error', $message);
                return redirect()->back();
            }

            $purchaseData = Envato::isValidPurchaseCode($request->purchaseCode, $request->envatoUsername, '');

            if (!$purchaseData->status) {
                $this->helper->one_time_message('error', __('Please provide valid purchase code and username.'));
                return redirect()->back();
            }

            if (!class_exists('ZipArchive')) {
                $this->helper->one_time_message('error', __('Something went wrong please try again.'));
                return redirect()->back();
            }

            $zip = new ZipArchive;
            $res = $zip->open($request->attachment);

            $upgraderDirecotory = storage_path('updates');

            if (is_dir($upgraderDirecotory)) {
                File::deleteDirectory($upgraderDirecotory);
            }

            if ($res === true) {
                $res = $zip->extractTo($upgraderDirecotory);
                $zip->close();
            }

            $upgrader = (new UpgradeManager)->isValid();

            return view('upgrader::eligible', compact('upgrader'));
        }

        return back();
    }

    /**
     * Redirect to waiting page
     */
    private function waiting()
    {
       echo (new UpgradeManager)->view(route('systemUpdate.upgrade', ['process' => true]));
    }

    /**
     * Process the upgrade
     */
    private function process()
    {
        (new UpgradeManager)->run();

        echo "<p>" . __("You will be redirect to the system. If not, click :x", ['x' => "<a href='".url('/')."'>" . __('here') . "</a>"]) . "</p><meta http-equiv=\"refresh\" content=\"5;URL='".url('/')."'\" />";
    }

    /**
     * Check php configuration
     *
     * @return string
     */
    private function configuration()
    {
        $message = __('Validation failed.');

        $systemError = __('Please check system configuration, go to :x', ["x" => route("systemInfo.index")]);

        $configurations = PhpInfo::phpinfo_configuration();
        if (empty($configurations)) {
            return __('phpinfo() is disabled. Please contact with your hosting provider.');
        }

        $config = [
            (int)str_replace('M', '', $configurations['upload_max_filesize']) < 128,
            (int)$configurations['max_file_uploads'] < 20,
            (int)str_replace('M', '', $configurations['post_max_size']) < 128,
            (int)$configurations['max_execution_time'] < 600,
            (int)$configurations['max_input_time'] < 120,
            (int)$configurations['max_input_vars'] < 1000,
            (int)str_replace('M', '', $configurations['memory_limit']) < 256
        ];

        if (in_array(true, $config)) {
            return $systemError;
        }

        return $message;
    }
}
