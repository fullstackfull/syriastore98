<?php

namespace Workdo\EMailBox\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Setting;
use Webklex\IMAP\Message;
use Illuminate\Support\Facades\Validator;
use Webklex\IMAP\Facades\Client;
use Workdo\EMailBox\app\Emails\ReplyMail;
use Illuminate\Support\Facades\Mail;
use Workdo\EMailBox\app\Emails\EmailsSendMail;
use Workdo\EMailBox\app\Models\EmailboxCredentail;
use Workdo\EMailBox\app\Models\DemoMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;


class EmailBoxController extends Controller
{	
	public function setConfig()
	{
		$mail_credentail = EmailboxCredentail::where('created_by', auth()->id())->first();

		config(['imap.accounts.default.host' => (!empty($mail_credentail->emailbox_mail_host) && isset($mail_credentail->emailbox_mail_host)) ? $mail_credentail->emailbox_mail_host : ""]);
		config(['imap.accounts.default.port' => (!empty($mail_credentail->emailbox_incoming_port) && isset($mail_credentail->emailbox_incoming_port)) ? $mail_credentail->emailbox_incoming_port : "993"]);
		config(['imap.accounts.default.encryption' => (!empty($mail_credentail->emailbox_mail_encryption) && isset($mail_credentail->emailbox_mail_encryption)) ? $mail_credentail->emailbox_mail_encryption : "ssl"]);
		config(['imap.accounts.default.username' => (!empty($mail_credentail->emailbox_mail_username) && isset($mail_credentail->emailbox_mail_username)) ? $mail_credentail->emailbox_mail_username : ""]);
		config(['imap.accounts.default.password' => (!empty($mail_credentail->emailbox_mail_password) && isset($mail_credentail->emailbox_mail_password)) ? $mail_credentail->emailbox_mail_password : ""]);


		return Client::make([
			'host'          => config('imap.accounts.default.host'),
			'port'          => config('imap.accounts.default.port'),
			'encryption'    => config('imap.accounts.default.encryption'),
			'username'      => config('imap.accounts.default.username'),
			'password'      => config('imap.accounts.default.password'),
			'protocol'      => 'imap'
		]);
	}

	public function index($type = "")
	{
		if (auth()->user()->isAbleTo('Manage Emailbox')) {
			$emailboxConfig = EmailboxCredentail::where('created_by', auth()->id())->first();
			
			// If no credentials configured, show demo data
			if (!$emailboxConfig || empty($emailboxConfig->emailbox_mail_host) || empty($emailboxConfig->emailbox_mail_username)) {
				return $this->showDemoData($type);
			}
			
			try {
				$this->setConfig();

				$client = Client::make([
					'host'          => $emailboxConfig->emailbox_mail_host,
					'port'          => $emailboxConfig->emailbox_incoming_port,
					'encryption'    => $emailboxConfig->emailbox_mail_encryption,
					'username'      => $emailboxConfig->emailbox_mail_username,
					'password'      => $emailboxConfig->emailbox_mail_password,
					'protocol'      => 'imap',
				]);
				$client->connect();
				if ($type == "sent") {
					if($client->getFolder('Sent'))
					{
						$folder = $client->getFolder('Sent');
					}
					else
					{
						$folder = $client->getFolder('Sent Mail');
					}
				} elseif ($type == "trash") {
					if ($client->getFolder('Bin')) {
						$folder = $client->getFolder('Bin');
					} else {
						$folder = $client->getFolder('Trash');
					}
				} elseif ($type == "drafts") {
					$folder = $client->getFolder('Drafts');
				} elseif ($type == "spam") {
					if ($client->getFolder('Spam')) {
						$folder = $client->getFolder('Spam');
					} else {
						$folder = $client->getFolder('Junk');
					}
				} elseif ($type == "archive") {
					
					if ($client->getFolder('Important')) {
						$folder = $client->getFolder('Important');
					}
					else{
						$folder = $client->getFolder('Archive');
					}
				} elseif ($type == 'starred') {
					$folder = $client->getFolder('Starred') ?? $client->getFolder('INBOX');
				} else {
					$folder = $client->getFolder('INBOX');
				}

				$messages = $folder->query()->all()->get(); // Retrieve all messages

				// Convert messages to a Laravel collection for easier manipulation
				$messagesCollection = collect($messages);

				// Paginate the messages
				$currentPage = LengthAwarePaginator::resolveCurrentPage();
				$perPage = 10; // Adjust items per page as needed
				$currentPageItems = $messagesCollection->slice(($currentPage - 1) * $perPage, $perPage)->values();
				$messages = new LengthAwarePaginator(
					$currentPageItems,
					$messagesCollection->count(),
					$perPage,
					$currentPage,
					['path' => LengthAwarePaginator::resolveCurrentPath()]
				);

				return view('email-box::mail.index', compact('messages'));

			} catch (\Exception $e) {
				\Log::error('Error connecting to IMAP server: ' . $e->getMessage());
				return $this->showDemoData($type);
			}
		} else {
			return redirect()->back()->with('error', __('Permission denied.'));
		}
	}

	private function showDemoData($type = "")
	{
		$demoDataPath = base_path('packages/workdo/EMailBox/src/resources/assets/mailData.json');;
		$demoData = json_decode(file_get_contents($demoDataPath), true);
		
		$folderMap = [
			'sent' => 'sent',
			'trash' => 'trash', 
			'drafts' => 'drafts',
			'spam' => 'spam',
			'archive' => 'archive',
			'starred' => 'starred',
			'' => 'inbox'
		];
		
		$folder = $folderMap[$type] ?? 'inbox';
		$emails = $demoData[$folder] ?? [];
		
		// Convert demo data to message-like objects
		$messages = collect($emails)->map(function($email) {
			return new DemoMessage($email);
		});
		
		// Convert demo data to paginated format
		$currentPage = LengthAwarePaginator::resolveCurrentPage();
		$perPage = 10;
		$currentPageItems = $messages->slice(($currentPage - 1) * $perPage, $perPage)->values();
		
		$paginatedMessages = new LengthAwarePaginator(
			$currentPageItems,
			$messages->count(),
			$perPage,
			$currentPage,
			['path' => LengthAwarePaginator::resolveCurrentPath()]
		);
		
		return view('email-box::mail.index', ['messages' => $paginatedMessages])->with('isDemoMode', true);
	}

	/**
	 * Show the form for creating a new resource.
	 * @return Renderable
	 */
	public function create($id = null, $type = null)
	{
		if (Auth::user()->isAbleTo('Emailbox mail sent')) {
			if (!empty($id) && !empty($type)) {
				try {
					$client = $this->setConfig();

					if ($type == "sent") {
					if($client->getFolder('Sent'))
					{
						$folder = $client->getFolder('Sent');
					}
					else
					{
						$folder = $client->getFolder('Sent Mail');
					}
				} elseif ($type == "trash") {
					if ($client->getFolder('Bin')) {
						$folder = $client->getFolder('Bin');
					} else {
						$folder = $client->getFolder('Trash');
					}
				} elseif ($type == "drafts") {
					$folder = $client->getFolder('Drafts');
				} elseif ($type == "spam") {
					if ($client->getFolder('Spam')) {
						$folder = $client->getFolder('Spam');
					} else {
						$folder = $client->getFolder('Junk');
					}
				} elseif ($type == "archive") {
					
					if ($client->getFolder('Important')) {
						$folder = $client->getFolder('Important');
					}
					else{
						$folder = $client->getFolder('Archive');
					}
				} elseif ($type == 'starred') {
					$folder = $client->getFolder('Starred') ?? $client->getFolder('INBOX');
				} else {
					$folder = $client->getFolder('INBOX');
				}

					$message = $folder->query()->getMessageByMsgn($id);

					if ($message) {
						return view('email-box::mail.create')->with('message', $message);
					} else {
						return redirect()->route('emailbox.index', $type)->with('error', "Unable to fetch mail");
					}
				} catch (\Throwable $th) {
					return redirect()->route('emailbox.setting')->with('error', __('Unable to connect with imap server please set your credentail & set Email Box setting.'));
				}
			} else {
				return view('email-box::mail.create');
			}
		} else {
			return redirect()->back()->with('error', __('Permission denied.'));
		}
	}

	/**
	 * Store a newly created resource in storage.
	 * @param Request $request
	 * @return Renderable
	 */
	public function store(Request $request)
	{
		if (Auth::user()->isAbleTo('Emailbox mail sent')) {
			$client = $this->setConfig();

			$attachments = [];
			if ($request->file('attachment')) {
				foreach ($request->file('attachment') as $key => $file) {

					$filenameWithExt = $request->file('attachment')[$key]->getClientOriginalName();
					$filename        = pathinfo($filenameWithExt, PATHINFO_FILENAME);
					$extension       = $request->file('attachment')[$key]->getClientOriginalExtension();
					$fileNameToStore = $filename . '_' . time() . '.' . $extension;

					$uplaod = multi_upload_file($file, 'attachment', $fileNameToStore, 'mail_attachment');

					if ($uplaod['flag'] == 1) {
						$attachments[] = $uplaod['url'];
					}
				}
			}
			$subject = $request->subject;
			$message = $request->content;
			$to = $request->to;
			$cc = $request->cc;
			$email = new EmailsSendMail($subject, $message);
		
			$email->to($to);
			if ($cc) {
				$email->cc($cc);
			}
			foreach ($attachments as $attachment) {
				$email->attach($attachment);
			}
			$user = Auth::user();
			$setconfing = $this->SetConfigEmailBoxMail();
		
			if ($setconfing ==  true) {
				try {
					Mail::send($email);

					if (!empty($request->mail_id)) {
						$folder = $client->getFolder('Drafts');

						$messages = $folder->query()->getMessageByMsgn($request->mail_id);

						if ($messages) {
                            if($client->getFolder('Sent'))
                            {
                                $moveToFolder = $client->getFolder('Sent');
                            }
                            else
                            {
                                $moveToFolder = $client->getFolder('Sent Mail');
                            }
							$message->move($moveToFolder->path);
						}
					} else {
                        if($client->getFolder('Sent'))
                        {
                            $folder = $client->getFolder('Sent');
                        }
                        else
                        {
                            $folder = $client->getFolder('Sent Mail');
                        }

						$mimeMessage = $this->createMimeMessage($subject, $message, $to, $cc, $attachments);
						$folder->appendMessage($mimeMessage);
					}

					return redirect()->route('emailbox.index', 'sent')->with('success', __('Mail sent successfully.'));
				} catch (\Exception $e) {

					return redirect()->route('emailbox.index', 'inbox')->with('error', $e);
				}
			} else {

				return redirect()->route('emailbox.index', 'inbox')->with('error', __('Something went wrong please try again'));
			}
		} else {
			return redirect()->route('emailbox.index', 'inbox')->with('error', __('Permission denied.'));
		}
	}

	protected function createMimeMessage($subject, $message, $to, $cc, $attachments)
	{
		$mail_credentail = EmailboxCredentail::where('created_by', Auth::user()->id)->first();
		$headers = [
			'From' => $mail_credentail['emailbox_mail_from_address'],
			'To' => $to,
			'Subject' => $subject,
			'Date' => now()->toRfc1123String(),
			'Content-Type' => 'text/html; charset=UTF-8',
		];

		if (!empty($cc)) {
			$headers['Cc'] = $cc;
		}

		// Prepare the body and handle attachments (you may want to enhance this)
		$body = $message;

		// Handle attachments
		$attachmentHeaders = '';
		if (!empty($attachments)) {
			foreach ($attachments as $attachment) {
				$attachmentHeaders .= 'Content-Disposition: attachment; filename="' . basename($attachment) . '"\r\n';
				$attachmentHeaders .= 'Content-Type: application/octet-stream; name="' . basename($attachment) . '"\r\n';
				$attachmentHeaders .= 'Content-Transfer-Encoding: base64\r\n';
				$attachmentHeaders .= "\r\n" . chunk_split(base64_encode(file_get_contents($attachment))) . "\r\n";
			}
		}

		// Construct the MIME message
		$mimeMessage = "From: {$headers['From']}\r\n";
		$mimeMessage .= "To: {$headers['To']}\r\n";
		if (isset($headers['Cc'])) {
			$mimeMessage .= "Cc: {$headers['Cc']}\r\n";
		}
		$mimeMessage .= "Subject: {$headers['Subject']}\r\n";
		$mimeMessage .= "Date: {$headers['Date']}\r\n";
		$mimeMessage .= "Content-Type: {$headers['Content-Type']}\r\n";
		$mimeMessage .= "\r\n{$body}";
		$mimeMessage .= $attachmentHeaders;

		return $mimeMessage;
	}

	/**
	 * Show the specified resource.
	 * @param int $id
	 * @return Renderable
	 */
	public function show($id, $type)
	{
		$emailboxConfig = EmailboxCredentail::where('created_by', auth()->id())->first();
		
		// If no credentials configured, show demo data
		if (!$emailboxConfig || empty($emailboxConfig->emailbox_mail_host) || empty($emailboxConfig->emailbox_mail_username)) {
			return $this->showDemoMessage($id, $type);
		}
		
		try {
			$client = $this->setConfig();
			if ($type == "sent") {
                if($client->getFolder('Sent'))
                {
                    $folder = $client->getFolder('Sent');
                }
                else
                {
                    $folder = $client->getFolder('Sent Mail');
                }
			} elseif ($type == "trash") {
				if ($client->getFolder('Bin')) {
					$folder = $client->getFolder('Bin');
				} else {
					$folder = $client->getFolder('Trash');
				}
			} elseif ($type == "drafts") {
				$folder = $client->getFolder('Drafts');
			} elseif ($type == "spam") {
				if ($client->getFolder('Spam')) {
					$folder = $client->getFolder('Spam');
				} else {
					$folder = $client->getFolder('Junk');
				}
			} elseif ($type == "archive") {
				if ($client->getFolder('Archive')) {
				$folder = $client->getFolder('Archive');
				}
				else{
				$folder = $client->getFolder('Starred');
				}
			} else {
				$folder = $client->getFolder('INBOX');
			}

			$message = $folder->query()->getMessageByMsgn($id);

			if ($message) {


				return view('email-box::mail.show')->with('message', $message);
			} else {
				return redirect()->route('emailbox.index', $type)->with('error', "Unable to fetch mail");
			}
		} catch (\Throwable $th) {
			return $this->showDemoMessage($id, $type);
		}
	}

	private function showDemoMessage($id, $type)
	{
		$demoDataPath = __DIR__ . '/../../resources/assets/mailData.json';
		$demoData = json_decode(file_get_contents($demoDataPath), true);
		
		$folderMap = [
			'sent' => 'sent',
			'trash' => 'trash', 
			'drafts' => 'drafts',
			'spam' => 'spam',
			'archive' => 'archive',
			'starred' => 'starred',
			'' => 'inbox'
		];
		
		$folder = $folderMap[$type] ?? 'inbox';
		$emails = $demoData[$folder] ?? [];
		
		$message = collect($emails)->firstWhere('id', $id);
		
		if ($message) {
			return view('email-box::mail.show', compact('message'))->with('isDemoMode', true);
		} else {
			return redirect()->route('emailbox.index', $type)->with('error', "Unable to fetch mail");
		}
	}

	/**
	 * Show the form for editing the specified resource.
	 * @param int $id
	 * @return Renderable
	 */
	public function edit($id)
	{
		return redirect()->back();
		return view('email-box::edit');
	}

	/**
	 * Update the specified resource in storage.
	 * @param Request $request
	 * @param int $id
	 * @return Renderable
	 */
	public function update(Request $request, $id)
	{
		return redirect()->back();
	}

	/**
	 * Remove the specified resource from storage.
	 * @param int $id
	 * @return Renderable
	 */
	public function destroy($id, $type)
	{
		if (Auth::user()->isAbleTo('Emailbox mail delete')) {
			try {
				$client = $this->setConfig();
	
				// Retrieve all folders for debugging or testing purposes.
				$folders = $client->getFolders();
				
				// Initialize folder variable
				$folder = null;
	
				// Match folder based on type
				switch (strtolower($type)) {
					case "sent":
						$folder = $this->findFolder($client, ['Sent', 'Sent Mail']);
						break;
	
					case "trash":
						$folder = $this->findFolder($client, ['Trash', '[Gmail]/Bin', 'deleted']);
						break;
	
					case "drafts":
						$folder = $this->findFolder($client, ['Drafts', '[Gmail]/Drafts']);
						break;
	
					case "spam":
						$folder = $this->findFolder($client, ['Spam', '[Gmail]/Spam']);
						break;
	
					case "archive":
						$folder = $this->findFolder($client, ['Archive']);
						break;
	
					default:
						$folder = $this->findFolder($client, ['INBOX']);
						break;
				}
	
				if (!$folder) {
					return redirect()->route('emailbox.index', $type)->with('error', "Folder not found.");
				}
	
				// Fetch the message by its ID
				$message = $folder->query()->getMessageByMsgn($id);
	
				if ($message) {
					if ($type === 'trash') {
						// Permanently delete the message
						$message->delete();
						return redirect()->route('emailbox.index', $type)->with('success', "Mail deleted successfully.");
					} else {
						// Move the message to the Trash folder
						$moveToFolder = $this->findFolder($client, ['Trash', '[Gmail]/Bin']);
						if ($moveToFolder) {
							$message->move($moveToFolder->path);
							return redirect()->route('emailbox.index', $type)->with('success', "Mail moved to Trash successfully.");
						}
					}
				}
	
				return redirect()->route('emailbox.index', $type)->with('error', "Mail not found or cannot be deleted.");
			} catch (\Throwable $th) {
				return redirect()->route('emailbox.setting')->with('error', __('Unable to connect with IMAP server. Please verify your credentials and Email Box settings.'));
			}
		} else {
			return redirect()->back()->with('error', __('Permission denied.'));
		}
	}
	 
	/**
	 * Find a folder by matching multiple possible names.
	 *
	 * @param \Webklex\PHPIMAP\Client $client
	 * @param array $folderNames
	 * @return \Webklex\PHPIMAP\Folder|null
	 */
	private function findFolder($client, array $folderNames)
	{
		foreach ($folderNames as $name) {
			$folder = $client->getFolder($name);
			if ($folder) {
				return $folder;
			}
		}
		return null;
	}
	 
	
	
	public function action(Request $request)
	{
		$type= $request->folder;
		try {
			$client = $this->setConfig();

			if ($type == "sent") {
				if($client->getFolder('Sent'))
				{
					$folder = $client->getFolder('Sent');
				}
				else
				{
					$folder = $client->getFolder('Sent Mail');
				}
			} elseif ($type == "trash") {
				if ($client->getFolder('Bin')) {
					$folder = $client->getFolder('Bin');
				} else {
					$folder = $client->getFolder('Trash');
				}
			} elseif ($type == "drafts") {
				$folder = $client->getFolder('Drafts');
			} elseif ($type == "spam") {
				if ($client->getFolder('Spam')) {
					$folder = $client->getFolder('Spam');
				} else {
					$folder = $client->getFolder('Junk');
				}
			} elseif ($type == "archive") {
				
				if ($client->getFolder('Important')) {
					$folder = $client->getFolder('Important');
				}
				else{
					$folder = $client->getFolder('Archive');
				}
			} elseif ($type == 'starred') {
				$folder = $client->getFolder('Starred') ?? $client->getFolder('INBOX');
			} else {
				$folder = $client->getFolder('INBOX');
			}

			$result = "";
			foreach ($request->id as $id) {
				$message = $folder->query()->getMessageByMsgn($id);
		
				if ($request->action == 'starred') {
					$message->setFlag('Flagged');
					$flag = $message->getFlags();
					$result = ["status" => 1, 'msg' => __('Mail starred successfully.')];
				}
				if ($request->action == 'unstarred') {
					$message->removeFlag('Flagged');
					$flag = $message->getFlags();
					$flag = $message->getFlags();
					$result = ["status" => 0, 'msg' => __('Mail unstarred successfully.')];
				}
				if ($request->action == 'seen') {
					$message->setFlag('Seen');
					$flag = $message->getFlags();
					$result = ["status" => 1, 'msg' => __('Mail marked as seen successfully.')];
				}
				if ($request->action == 'unseen') {
					$message->removeFlag('Seen');
					$flag = $message->getFlags();
					$flag = $message->getFlags();
					$result = ["status" => 0, 'msg' => __('Mail marked as unseen successfully.')];
				}
				if ($request->action == 'move') {
				$type = $request->moveToFolder;
					
					if ($type == "sent") {
					if($client->getFolder('Sent'))
					{
						$folder = $client->getFolder('Sent');
					}
					else
					{
						$folder = $client->getFolder('Sent Mail');
					}
				} elseif ($type == "trash") {
					if ($client->getFolder('Bin')) {
						$folder = $client->getFolder('Bin');
					} else {
						$folder = $client->getFolder('Trash');
					}
				} elseif ($type == "drafts") {
					$folder = $client->getFolder('Drafts');
				} elseif ($type == "spam") {
					if ($client->getFolder('Spam')) {
						$folder = $client->getFolder('Spam');
					} else {
						$folder = $client->getFolder('Junk');
					}
				} elseif ($type == "archive") {
					
					if ($client->getFolder('Important')) {
						$folder = $client->getFolder('Important');
					}
					else{
						$folder = $client->getFolder('Archive');
					}
				} elseif ($type == 'starred') {
					$folder = $client->getFolder('Starred') ?? $client->getFolder('INBOX');
				} else {
					$folder = $client->getFolder('INBOX');
				}

				$moveToFolder = $folder;
				
					$message->move($moveToFolder->path);
					$result = ["status" => 1, 'msg' => __('Mail move successfully')];
				}
			}
			return $result;
		} catch (\Exception $e) {
			return redirect()->route('emailbox.setting')->with('error', __('Unable to connect with imap server please set your credentail & set Email Box setting.'));
		}
	}

	public function move_mail(Request $request)
	{
		try {
			$client = $this->setConfig();

			if ($request->folder == "sent") {
				if($client->getFolder('Sent'))
				{
					$folder = $client->getFolder('Sent');
				}
				else
				{
					$folder = $client->getFolder('Sent Mail');
				}
			} elseif ($request->folder == "trash") {
				$folder = $client->getFolderByName('Trash');
			} elseif ($request->folder == "drafts") {
				$folder = $client->getFolder('Drafts');
			} elseif ($request->folder == "spam") {
				$folder = $client->getFolder('spam');
			} elseif ($request->folder == "archive") {
				$folder = $client->getFolder('Archive');
			} else {
				$folder = $client->getFolder('INBOX');
			}
			$result = "";
			$message = $folder->query()->getMessageByMsgn($request->id);
			$moveToFolder = $client->getFolder("INBOX");
			$message->move($moveToFolder->path);
			$result = ["status" => 1, 'msg' => __('Mail move to inbox successfully.'), 'folder' => $request->folder];
			return $result;
		} catch (Exception $e) {

			return redirect()->route('emailbox.setting')->with('error', __('Unable to connect with imap server please set your credentail & set Email Box setting.'));
		}
	}
	 
	public function reply($id, $type)
	{
		if (auth()->user()->isAbleTo('Emailbox mail reply')) {
			try {
				$setConfig = $this->setConfig();
				$client = Client::account('default');
				$client->connect();
				if ($type == "sent") {
					$folder = $client->getFolder('Sent');
				} elseif ($type == "trash") {
					$folder = $client->getFolderByName('Trash');
				} elseif ($type == "drafts") {
					$folder = $client->getFolder('Drafts');
				} elseif ($type == "spam") {
					$folder = $client->getFolderByName('spam');
				} elseif ($type == "archive") {
					$folder = $client->getFolder('Archive');
				} else {
					$folder = $client->getFolder('INBOX');
				}
				$message = $folder->query()->getMessageByMsgn($id);
				if ($message) {
					return view('email-box::mail.reply')->with('message', $message);
				} else {
					return redirect()->route('emailbox.index', $type)->with('error', "Unable to fetch mail");
				}
			} catch (\Throwable $th) {
				return redirect()->route('emailbox.setting')->with('error', __('Unable to connect with imap server please set your credentail & set Email Box setting.'));
			}
		} else {
			return redirect()->back()->with('error', __('Permission denied.'));
		}
	}

	public function reply_send(Request $request)
	{
		if (auth()->user()->isAbleTo('Emailbox mail reply')) {
			$attachments = [];
			if ($request->file('attachment')) {
				foreach ($request->file('attachment') as $key => $file) {

					$filenameWithExt = $request->file('attachment')[$key]->getClientOriginalName();
					$filename        = pathinfo($filenameWithExt, PATHINFO_FILENAME);
					$extension       = $request->file('attachment')[$key]->getClientOriginalExtension();
					$fileNameToStore = $filename . '_' . time() . '.' . $extension;

					$uplaod = multi_upload_file($file, 'attachment', $fileNameToStore, 'mail_attachment');

					if ($uplaod['flag'] == 1) {
						$attachments[] = $uplaod['url'];
					}
				}
			}

			$subject = $request->subject;
			$content = $request->content;
			$to_mail = $request->to;
			$cc = $request->cc;
			$email = new ReplyMail($subject, $content, $to_mail);
			$email->to($to_mail);
			if ($cc) {
				$email->cc($cc);
			}
			foreach ($attachments as $attachment) {
				$email->attach($attachment);
			}
			$user = auth()->user();
			$setconfing = $this->SetConfigEmailBoxMail($user->id);
			if ($setconfing ==  true) {
				try {
					Mail::send($email);
					return redirect()->route('emailbox.index', 'sent')->with('success', __('Mail sent successfully.'));
				} catch (\Exception $e) {

					return redirect()->route('emailbox.index', 'inbox')->with('error', $e);
				}
			} else {

				return redirect()->route('emailbox.index', 'inbox')->with('error', __('Something went wrong please try again'));
			}
		} else {
			return redirect()->back()->with('error', __('Permission denied.'));
		}
	}

	public static function SetConfigEmailBoxMail($user_id = null, $store_id = null)
	{
		
		$user_id = auth()->id();
		try {
			
			$mail_credentail = EmailboxCredentail::where('created_by', auth()->id())->first();
			config(
				[
					'mail.driver' => $mail_credentail['emailbox_mail_driver'],
					'mail.host' => $mail_credentail['emailbox_mail_host'],
					'mail.port' => $mail_credentail['emailbox_outgoing_port'],
					'mail.encryption' => $mail_credentail['emailbox_mail_encryption'],
					'mail.username' => $mail_credentail['emailbox_mail_username'],
					'mail.password' => $mail_credentail['emailbox_mail_password'],
					'mail.from.address' => $mail_credentail['emailbox_mail_from_address'],
					'mail.from.name' => $mail_credentail['emailbox_mail_from_name'],
				]
			);
			return true;
		} catch (\Exception $e) {
			return false;
		}	
	}
	
}
