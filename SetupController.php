<?php

namespace App\Http\Controllers;

use App\Enum\ShopStatus;
use App\Jobs\AnalyseShop;
use App\Mail\ShopRegistered;
use App\Services\ShopifyService;
use App\Shop;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SetupController extends Controller
{
    public function step1(Request $request)
    {
        /** @var Shop $shop */
        $shop = Auth::guard('app')->user();

        $form = new \stdClass();
        $form->phones = join("\n", explode(',', $shop->phones));
        $form->business = $shop->name;
        $form->phone = $shop->phone;
        $form->away_message = $shop->away_message ?: 'You\'ve stumped me! It looks like no one is available to address your questions. Please reach out to us at (222) 333-4444 or by email at email@email.com.';

        if ($request->isMethod('post')) {

            $re = clone $request;
            $re->request->set('phones', array_map('trim', explode("\n", trim($request->request->get('phones')))));

            $this->validate($re, [
                'phones.*' => ['required', 'regex:/^\+?(\(\d{3}\))\d{3,12}|\+\d{6,15}$/'],
                'business' => 'required',
                'phone' => ['required', 'regex:/^\+?(\(\d{3}\))\d{3,12}|\+\d{6,15}$/'],
                'away_message' => 'required',
            ], [
                'phones.*.required' => 'The Phones list field is required.',
                'phones.*.regex' => 'The phone format is invalid. Invalid format: +1234567890 or +(123)4567890',
                'business.required' => 'The Business Name field is required.',
                'phone.required' => 'The Phone Number field is required.',
                'phone.regex' => 'The phone format is invalid. Invalid format: +1234567890 or +(123)4567890',
                'away_message.required' => 'The away message field is required.',
            ]);

            $form->away_message =  $request->post('away_message', '');
            $form->business =      $request->post('business', '');
            $form->phones =        $request->post('phones', '');
            $form->phone =         $request->post('phone', '');

            $list = array_map('trim', explode("\n", $form->phones));

            $shop->phones = join(",", $list);
            $shop->business = $form->business;
            $shop->away_message = $form->away_message;
            $shop->phone = $form->phone;

            $shop->steps = max($shop->steps, 2);

            if ($shop->save()) {
                if ($shop->steps >= 4)
                    $request->session()->flash('status', 'Settings was saved!');
                $next = $request->post('next');
                return redirect($next);
            }
        }

        return view('shopify.setup.step1', [
            'shop' => $shop,
            'form' => $form
        ]);
    }

    public function step2(Request $request)
    {
        /** @var Shop $shop */
        $shop = Auth::guard('app')->user();

        if ($shop->steps < 1) {
            return redirect('step1');
        }

        $form = new \stdClass();
        $form->sms_enabled = $shop->sms_enabled;
        $form->sms_template = $shop->sms_template;
        $form->facebook_enabled = $shop->facebook_enabled;
        $form->twitter_enabled = $shop->twitter_enabled;
        $form->agent_enabled = $shop->agent_enabled;
        $form->checkout_interval = $shop->checkout_interval;

        if ($request->isMethod('post')) {

            $form->sms_enabled = $shop->sms_enabled = $request->post('sms_enabled');
            $form->sms_template = $shop->sms_template = $request->post('sms_template');
            $form->facebook_enabled = $shop->facebook_enabled = $request->post('facebook_enabled');
            $form->twitter_enabled = $shop->twitter_enabled = $request->post('twitter_enabled');
            $form->agent_enabled = $shop->agent_enabled = $request->post('agent_enabled');
            $form->checkout_interval = $shop->checkout_interval = $request->post('checkout_interval');

            $shop->steps = max($shop->steps, 2);

            if ($shop->save()) {
                if ($shop->steps >= 4)
                    $request->session()->flash('status', 'Settings was saved!');
                $next = $request->post('next');
                return redirect($next);
            }
        }

        return view('shopify.setup.step2', [
            'shop' => $shop,
            'form' => $form
        ]);
    }

    public function step3(Request $request)
    {
        /** @var Shop $shop */
        $shop = Auth::guard('app')->user();

        if ($shop->steps < 2) {
            return redirect('step' . $shop->steps);
        }

        $tiers0 = $shop->tiers0 ? json_decode($shop->tiers0) : [
            ['Sales', 'Thank you for choosing Sales. Which of the following best applies for your inquiry?'],
            ['Support', 'Thank you for choosing Support. Which of the following best applies for your inquiry?']
        ];
        $tiers1 = $shop->tiers1 ? json_decode($shop->tiers1) : [
            ['Promotions', 'Thank you for choosing Promotions. Which of the following best applies for your inquiry?'],
            ['Product Questions', 'Thank you for choosing Product Questions. Which of the following best applies for your inquiry?']
        ];
        $tiers2 = $shop->tiers2 ? json_decode($shop->tiers2) : [
            ['Returns', 'Thank you for choosing Returns. Which of the following best applies for your inquiry?'],
            ['Shipping', 'Thank you for choosing Shipping. Which of the following best applies for your inquiry?']
        ];

        $form = new \stdClass();
        $form->chat_enabled = $shop->chat_enabled;

        $form->tiers = [
            $tiers0,
            $tiers1,
            $tiers2,
        ];

        if ($request->isMethod('post')) {
            $data = json_decode($request->post('tiers'), true);

            $form->chat_enabled = $shop->chat_enabled = $request->post('chat_enabled');

            $shop->tiers0 = json_encode($data[0], JSON_UNESCAPED_UNICODE);
            $shop->tiers1 = json_encode($data[1], JSON_UNESCAPED_UNICODE);
            $shop->tiers2 = json_encode($data[2], JSON_UNESCAPED_UNICODE);

            $form->tiers = [
                $shop->tiers0,
                $shop->tiers1,
                $shop->tiers2,
            ];

            $shop->steps = max($shop->steps, 3);

            if ($shop->save()) {
                if ($shop->steps >= 4) {

                    $service = new ShopifyService($shop);
                    $service->createScriptTag(true, $shop->chat_enabled);

                    $request->session()->flash('status', 'Settings was saved!');
                }
                $next = $request->post('next');
                return redirect($next);
            }
        }

        return view('shopify.setup.step3', [
            'shop' => $shop,
            'form' => $form
        ]);
    }

    public function step4(Request $request)
    {
        /** @var Shop $shop */
        $shop = Auth::guard('app')->user();

        if ($shop->steps < 3) {
            return redirect('step' . $shop->steps);
        }

        $form = new \stdClass();

        if ($request->isMethod('post')) {

            if ($shop->status == ShopStatus::NEW) {
                $this->validate($request, [
                    'package' => 'required',
                    'agree' => 'required',
                ], [
                    'package.required' => 'You should select pagage.',
                    'agree.required' => 'Please, agree with User Software License Agreement.',
                ]);
                $shop->package = $form->package = $request->post('package');
                $shop->steps = max($shop->steps, 4);
            }
            else {
                $form->next_package = $request->post('next_package');

                // change package?
                $inTrial = (new \Carbon\Carbon())->subDays(env('DAYS_FOR_TRIAL'))->lessThan(new \Carbon\Carbon($shop->created_at, 'GMT'));
                if ($inTrial) {
                    if (!empty($form->next_package)) {
                        $shop->package = $form->next_package;
                        $shop->save();
                    }
                } else {
                    if ($form->next_package == $shop->package) {
                        $shop->next_package = "";
                        $shop->next_package_since = null;
                    } else {
                        $shop->next_package = $form->next_package;
                        $shop->next_package_since = Carbon::now('GMT')->startOfMonth()->addMonth()->toDateTimeString();
                    }
                    $shop->save();
                }
            }

            if ($shop->save()) {
                $next = $request->post('next');
                if ($next == 'setup') {

                    if ($shop->status == ShopStatus::NEW) {
                        $shop->status = ShopStatus::PENDING;

                        if ($shop->save()) {
                            AnalyseShop::dispatch($shop->id)->onQueue('revebot-shop');
                            Mail::to((object)['email' => env('MAIL_TO_ADDRESS'), 'name' => env('MAIL_TO_NAME')])->send(new ShopRegistered($shop));
                            return redirect("/pending");
                        }
                    }
                    else {
                        return redirect("/dashboard");
                    }
                } else {
                    if ($shop->steps >= 4)
                        $request->session()->flash('status', 'Settings was saved!');
                    return redirect($next);
                }
            }
        } else {
            $form->package = $shop->steps >= 4 ? $shop->package : "";
            $form->next_package = $shop->steps >= 4 ? ($shop->next_package ?: $shop->package) : "";
            $form->agree = $shop->steps >= 4;
        }

        return view('shopify.setup.step4', [
            'shop' => $shop,
            'form' => $form
        ]);
    }

    /**
     * @deprecated
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     * @throws \Illuminate\Validation\ValidationException
     */
    public function index(Request $request)
    {
        /** @var Shop $shop */
        $shop = Auth::guard('app')->user();

        $form = new \stdClass();
        $form->business = $shop->company;
        $form->website = 'https://' . $shop->domain . '/';
        $form->reference = '';
        $form->phone = '';
        $form->process = '';
        $form->visitors = '';
        $form->avg_order = '';
        $form->mon_sales = '';
        $form->options = [];
        $form->channels = [];

        if ($request->isMethod('post')) {
            $this->validate($request, [
                'business' => 'required',
                'website' => 'required',
                'visitors' => 'required',
                'avg_order' => 'required',
                'mon_sales' => 'required',
                'agree' => 'required',
                'phone' => ['required', 'regex:/^\+?(\(\d{3}\))\d{3,12}|\+\d{6,15}$/'],
            ], [
                'business.required' => 'The Business Name field is required.',
                'phone.required' => 'The Phone Number field is required.',
                'website.required' => 'The Website Address field is required.',
                'visitors.required' => 'Please, select Unique Monthly Website Visitors.',
                'avg_order.required' => 'Please, select Average Order Values.',
                'mon_sales.required' => 'Please, select Average Number or Sales Per Month.',
                'agree.required' => 'Please, agree with User Software License Agreement.',
                'phone.regex' => 'The phone format is invalid. Invalid format: +1234567890 or +(123)4567890',
            ]);
            $shop->business =  $form->business =  $request->post('business', '');
            $shop->website =   $form->website =   $request->post('website', '');
            $shop->phone =   $form->phone =   $request->post('phone', '');
            $shop->reference = $form->reference = $request->post('reference', '');
            $shop->process =   $form->process =   $request->post('process', '');
            $shop->visitors =  $form->visitors =  $request->post('visitors', 0);
            $shop->avg_order = $form->avg_order = $request->post('avg_order', 0);
            $shop->mon_sales = $form->mon_sales = $request->post('mon_sales', 0);

            $form->options =  array_keys($request->post('options')  ?? []);
            $form->channels = array_keys($request->post('channels') ?? []);

            $shop->options =  join(',', $form->options);
            $shop->channels = join(',', $form->channels);

            $shop->status = ShopStatus::PENDING;

            if ($shop->save()) {
                AnalyseShop::dispatch($shop->id)->onQueue('revebot-shop');
                Mail::to((object)['email' => env('MAIL_TO_ADDRESS'), 'name' => env('MAIL_TO_NAME') ])->send(new ShopRegistered($shop));
                return redirect("/pending");
            }
        }

        return view('shopify.welcome', [
            'shop' => $shop,
            'form' => $form,
        ]);
    }

    public function pending(Request $request)
    {
        $shop = Auth::guard('app')->user();
        return view('shopify.setup.pending', [
            'shop' => $shop,
        ]);
    }

    public function sorry(Request $request)
    {
        $shop = Auth::guard('app')->user();
        return view('shopify.setup.sorry', [
            'shop' => $shop,
        ]);
    }
}
