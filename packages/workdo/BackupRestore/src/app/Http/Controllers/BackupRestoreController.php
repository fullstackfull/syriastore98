<?php

namespace Workdo\BackupRestore\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Workdo\BackupRestore\app\Models\BackupRestoreLog;
use Workdo\BackupRestore\DataTables\BackupRestoreData;

class BackupRestoreController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(BackupRestoreData $dataTable)
    {
        if (module_is_active('BackupRestore')) {
            if (Auth::user() && Auth::user()->isAbleTo('backup&restore manage')) {
                return $dataTable->render('backup-restore::backup-restore.index');
            } else {
                return redirect()->back()->with('error', __('Permission Denied!'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied!'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        if (Auth::user() && Auth::user()->isAbleTo('backup&restore create')) {
            return view('backup-restore::backup-restore.create');
        } else {
            return redirect()->back()->with('error', __('Permission Denied!'));
        }
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if ($request['files'] != 'on' && $request->database != 'on') {
            return response()->json([
                'status' => false,
                'msg' => __('Please select an option.')
            ]);
        }
        if ($request['files'] == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'file_name' => 'required',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'msg' => __('File Name field is required.')
                ]);
            }

            try {
                $backupPath = base_path('storage/uploads');

                $appUrlSubdirectory = 'Files_Backup';
                $backupPath .= '/' . $appUrlSubdirectory;
                if (!file_exists($backupPath)) {
                    mkdir($backupPath, 0777, true);
                }
                $filename = $request->file_name . '_' . date('YmdHis') . '.zip';
                $backupFilePath = $backupPath . '/' . $filename;
                ini_set('max_execution_time', 600);

                // Create the ZIP archive
                $zip = new ZipArchive();
                if ($zip->open($backupFilePath, ZipArchive::CREATE) === true) {

                    $pathsToBackup = [
                        base_path(),
                    ];

                    foreach ($pathsToBackup as $path) {
                        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);

                        foreach ($files as $file) {
                            $filePath = $file->getPathname();
                            $relativePath = substr($filePath, strlen($path) + 1);
                            $getedFilename = $file->getFilename();
                            $fileExtension = pathinfo($getedFilename, PATHINFO_EXTENSION);

                            if (!in_array($getedFilename, ['.', '..']) && $fileExtension !== 'zip') {
                                if ($file->isFile()) {
                                    $zip->addFile($filePath, $relativePath);
                                } elseif ($file->isDir()) {
                                    $zip->addEmptyDir($relativePath);
                                }
                            }
                        }
                    }

                    if ($zip->close()) {
                        BackupRestoreLog::create([
                            'file_name'     => $filename,
                            'type'          => 'File',
                            'created_by'    => Auth::user()->id,
                            'store_id'      => Auth::user()->current_store ?? null
                        ]);
                    }
                    File::chmod($backupPath, 0777);
                    File::chmod($backupFilePath, 0777);
                } else {
                    return response()->json([
                        'status' => false,
                        'msg' => __('Something went wrong.')
                    ]);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'status' => false,
                    'msg' => $e->getMessage()
                ]);
            }
        }

        if ($request->database == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'database_name' => 'required',
                ]
            );
            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'msg' => __('Database Name field is required.')
                ]);
            }
            try {
                $this->backupDatabase($request->database_name);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => false,
                    'msg' => $e->getMessage()
                ]);
            }
        }
        return response()->json([
            'status' => true,
            'msg' => __('Data backup successful.')
        ]);
    }


    private function backupDatabase($database_name)
    {
        try {
            $backupPath = base_path('storage/uploads');

            $appUrlSubdirectory = 'Database_Backup';
            $backupPath .= '/' . $appUrlSubdirectory;

            if (!file_exists($backupPath)) {
                mkdir($backupPath, 0777, true);
            }

            $filename = $database_name . '_' . date('YmdHis') . '.sql';
            $backupFilePath = $backupPath . '/' . $filename;

            $databaseName = config('database.connections.mysql.database');
            $username = config('database.connections.mysql.username');
            $password = config('database.connections.mysql.password');

            $command = "mysqldump -u {$username} -p{$password} {$databaseName} > {$backupFilePath}";

            shell_exec($command);
            
            $this->saveDatabaseBackupInformation($filename);
            File::chmod($backupPath, 0777, true);
            File::chmod($backupFilePath, 0777, true);

            return response()->json([
                'status' => true,
                'msg' => __('Data backup successful.')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'msg' => $e->getMessage()
            ]);
        }
    }

    private function saveDatabaseBackupInformation($filename)
    {
        BackupRestoreLog::create([
            'file_name'     => $filename,
            'type'          => 'Database',
            'created_by'    => Auth::user()->id,
            'store_id'      => Auth::user()->current_store ?? null
        ]);
    }
    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        // return view('backup-restore::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        // return view('backup-restore::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {

        if(Auth::user() && Auth::user()->isAbleTo('backup&restore delete'))
        {
            $backup_file = BackupRestoreLog::find($id);
            if ($backup_file->type == 'File') {
                $filePath = 'storage/uploads/Files_Backup' . '/' . $backup_file->file_name;
            } elseif ($backup_file->type == 'Database') {
                $filePath = 'storage/uploads/Database_Backup' . '/' . $backup_file->file_name;
            }

            if (File::exists($filePath)) {
                File::delete($filePath);
            }
            $backup_file->delete();

            return redirect()->back()->with('success',__('Backup file successfully deleted.'));
        } else {
            return redirect()->back()->with('error',__('Permission Denied!'));
        }
    }
}
