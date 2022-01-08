<?php

namespace App\Services\Account;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

//Services
use App\Services\BaseServices;
use App\Services\Validation\Account\DepositValidation;

//Models
use App\Models\Deposit;
use App\Models\Tempregister;
use App\Models\Account;
use App\Models\Scantracker;

//Utilities
use App\Utilities\HttpUtilities;

class DepositServices extends BaseServices{

    /**
     * http://54.183.16.194/bank_transactions?recipient=a5dbcded3501291743e0cb4c6a186afa2c87a54f4a876c620dbd68385cba80d0&ordering=-block__created_date
     * http://54.183.16.194/bank_transactions?recipient=8c44cb32b7b0394fe7c6a8c1778d19d095063249b734b226b28d9fb2115dbc74&ordering=-block__created_date
     * http://54.183.16.194/confirmation_blocks?block=aa5c09a7-c573-4dd1-b06b-b123eb5880ff
     * http://54.183.16.194/confirmation_blocks?block=&block__signature=a44d171d9f0ba0f6d0c0c489b9a17d24dd38734a4428fdf85ea08e1ca821086dae6601d20c3a0bcfc94e73b3f77092026d179391752702dd76adf38c50b8cb06
     */
    private  $depositModel = Deposit::class;
    private  $registerModel = Tempregister::class;
    private  $accountModel = Account::class;
    private  $scanTrackerModel = Scantracker::class;

    public function storeDeposits(){
        /**
         * Fetch bank transactions from bank
         * Insert new deposits into database
         */
        $app_pk = '9c22ee4f664e7167f9a67f2a882240de6e34ee61a01af7ce8995ad74958b81e8';
        $protocol = 'http';
        // $bank = '54.183.16.194';
        $bank = '20.98.98.0';
        $next_url = $protocol.'://'.$bank.'/bank_transactions?recipient='.$app_pk.'&ordering=-block__created_date';
        
        while($next_url) {
            $data = HttpUtilities::fetchUrl($next_url);
            $bankTransactions = $data->results;
            $next_url = $data->next;

            foreach($bankTransactions as $bankTransaction){
                
                $transactionExist = Deposit::where('transaction_id',$bankTransaction->id)->first();
                if($transactionExist){
                    continue;
                }
                $deposit = $this->baseRI->storeInDB(
                    $this->depositModel,
                    [
                        'transaction_id'=> $bankTransaction->id,
                        'amount'=>$bankTransaction->amount,
                        'block_id'=>$bankTransaction->block->id,
                        'confirmation_checks' => 0,
                        'is_confirmed'=> false,
                        'memo'=>$bankTransaction->memo,
                        'sender'=>$bankTransaction->block->sender,
                    ]
                );
            }
            $lastDepositId = DB::getPdo()->lastInsertId();
            if($lastDepositId){
                $lastDeposit = Deposit::where('id',$lastDepositId)->first();
                $lastScanned = $this->baseRI->storeInDB(
                    $this->scanTrackerModel,
                    [
                        'last_scanned'=> $lastDeposit->created_at,
                    ]
                );
                return response(["message"=>$lastScanned],201);
            }else{
                return response(["message"=>'ok'],201);
            }
        }
    }

    public function businessLogics($deposit){
        /**
         * Update confirmation status of deposit
         * Increase users balance or create new user if they don't already exist
        */
        $deposit->is_confirmed = true;
        $deposit->save();

        //check register model
        $requestRegistration = Tempregister::where('account_number',$deposit->sender)->where('verification_code', $deposit->memo)->first();
        if($requestRegistration){
            //create new account
            $account = $this->baseRI->storeInDB(
                $this->accountModel,
                [
                    'user_id' => auth()->user()->id,
                    'account_number' => $requestRegistration->account_number,
                    'balance' => 0
                ]
            );
            if($account){
                $requestRegistration->delete();
            }
        }else{
            $account = Account::where('account_number',$deposit->sender)->first();
            $account->balance = $account->balance + $deposit->amount;
            $account->save();
        }
    }

    public function checkConfirmations(){
        /**
         * Check bank for confirmation status
         * Query unconfirmed deposits from database
         */
        $maxConfirmationChecks = 15;
        $protocol = 'http';
        // $bank = '54.183.16.194';
        $bank = '20.98.98.0';
        
        $unconfirmedDeposits = Deposit::where('is_confirmed',0)->where('confirmation_checks', '<', $maxConfirmationChecks)->get();
        foreach($unconfirmedDeposits as $deposit){
            // $blockId = $deposit->block_id;
            // $url = $protocol.'://'.$bank.'/confirmation_blocks?block='.$blockId;
            // $data = HttpUtilities::fetchUrl($url);
            // $confirmation = $data->count;
            $confirmation = 1;
            if($confirmation){
                $this->businessLogics($deposit);
            }else{
                $deposit->confirmation_checks +=1;
                $deposit->save();
            }
        }
        // return $unconfirmedDeposits;
        // return $blockId;
    }

    public function pullBlockchain(){
        /**
        * Poll blockchain for new transactions/deposits sent to the account
        * Only accept confirmed transactions
        */
        $this->storeDeposits();
        $this->checkConfirmations();
    }
}