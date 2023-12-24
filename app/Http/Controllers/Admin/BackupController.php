<?php

namespace App\Http\Controllers\Admin;

use App\DataTables\Admin\BackupsDataTable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use DB, Common, Config;
use App\Models\Backup;

class BackupController extends Controller
{
    protected $helper;

    public function __construct()
    {
        $this->helper = new Common();
    }

    public function index(BackupsDataTable $dataTable)
    {
        $data['menu'] = 'settings';
        $data['settings_menu']     = 'backup';
        $data['is_demo'] = $is_demo = checkDemoEnvironment(); // Check if it is in demo environment or not
        return $dataTable->render('admin.backups.view', $data);
    }

    public function add(Request $request)
    {
        $backup_name = $this->helper->backup_tables(env('DB_HOST'), env('DB_USERNAME'), env('DB_PASSWORD'), env('DB_DATABASE'));
        if ($backup_name != 0)
        {
            DB::table('backups')->insert(['name' => $backup_name, 'created_at' => date('Y-m-d H:i:s')]);
            $this->helper->one_time_message('success', __('The :x has been successfully Saved.', ['x' => __('backup')]));
        }
        return redirect()->intended(config('adminPrefix')."/settings/backup");
    }

    public function download(Request $request)
    {
        $backup = Backup::find($request->id);
        $backupPath = public_path('uploads/db-backups/' . $backup->name);
        return response()->download($backupPath, $backup->name);
    }
}
