<?php
/* HOGAN, ALAN
 * Project 4
 * CSE 230 Aviral Shrivastava
 * http://alanhogan.com/contact
 *
 * Licensed under CC BY-NC-SA 4.0; please see README for more information
 *
 * PLEASE SEE THIS FILE IN ACTION at http://alanhogan.com/asu/simulator.php
 **/

/* Notes:
 * 
 * post "memInput" / print $output
 *
 * This program makes liberal use of Regular Expressions. If you are examining
 * or editing this file, please first become familiar with regular expressions,
 * (specifically, the Perl/PCRE engine).
 *
 * This program processes address-intruction/address-data pairs assembled from
 * MIPS code, along with input data, and creates output.
 * 
 * Please note that both Register and Memory internall store & return
 * data in STRING HEX of 8-char length. KEYS are INTEGERS but strings
 * are automatically converted (with register() and hexdec(), respectively).
 *
 */

/* * * * * * * *
Todo / Game plan

- Memory is just an associative array, hex code key & hex code data
- Load all pairs into memory
- while (!$exit)
    - Begin execution at 0x00400000 or custom location
    - Function "instruction_decode($hex)" takes hex, returns "Instruction" object
        - Instruction->type: R, I, J, Syscall (more??)
        - Instruction->opcode, rs, rt, rd, shamt, funct, imm, address: bit string
    - Exit with exit syscall or invalid instruction or invalid mem ref
- "Registers" object
- Support for syscall ops
- Create a stack model: Probably just 0xffffffff and then lower in the memory hash
- Support for jump
- Support for beq, bne
- Support for R-types
- Support for I-types
- Finish J-types & jal, jr.
- If jr and $ra = 0, then program execution is complete (success!)

- Opera problems resolved. Do not use "&nbsp;" within textareas. See: http://www.webdesignforums.net/showthread.php?t=28727

* * * * */

$debug = "";
$output = ""; //// important

define('SIMULATOR_VERSION', '0.10');

//Types of instructions
define('TYPE_UNASSIGNED', 0);
define('TYPE_COMMENT', 1);
define('TYPE_R', 2);
define('TYPE_I', 3);
define('TYPE_J', 4);
define('TYPE_SYSCALL', 5);
define('TYPE_LABEL', 6);
define('TYPE_INVALID', -1);

//For .data directives
define('DATATYPE_ASCIIZ', 25);

//Regex pattern for a register
define('REGEX_REGISTER', '((\$zero)|(\$[a-z][a-z0-9]))');
//Where instructions start
define('ADDRESS_START',0x00400000); //note, by default this will print integer/decimal format
//Data stored here
define('DATA_ADDRESS_START',0x00c00000);
//Top of stack
define('STACK_TOP_ADDRESS',0x7ffffffc); //Any higher, and it will be a negative number

function mem_dechex($dec) {
    return sprintf("%08x", $dec);
}

class Instruction {
    public $type;
    public $opcode;
    public $rs;
    public $rt;
    public $rd;
    public $shamt;
    public $funct;
    public $imm;
    public $address;
    public function __constructor() {
        $this->type = TYPE_UNASSIGNED;
        $this->opcode = "0";
        $this->rs = "0";
        $this->rt = "0";
        $this->rd = "0";
        $this->shamt = "0";
        $this->funct = "0";
        $this->imm = "0";
        $this->address = "0";
        $this->opcodedec     = 0;
        $this->rsdec         = 0;
        $this->rtdec         = 0;
        $this->rddec         = 0;
        $this->shamtdec    = 0;
        $this->functdec    = 0;
        $this->immdec         = 0;
        $this->addressdec    = 0;
    }
}

class Registers {
    private $registers;
    
    public function __construct() {
        for($x = 0; $x<32; $x++)
            $this->registers[$x]="00000000";
        $this->registers[register('$sp')] = dechex(STACK_TOP_ADDRESS);
    }
    public function read($register) {
        if(is_string($register)) $register = register($register);
        return $this->registers[$register];
    }
    public function write($register, $value) {
        global $debug;
        if(is_string($register)) $register = register($register);
        if(is_string($value) && strlen($value) > 15) {
            if($_POST['debug_on']) $debug .= "Convert binary register value to hex: $value > ";
            $value = sprintf("%08x", (int) bindec($value));
            if($_POST['debug_on']) $debug .= "$value\n";
        } elseif(is_int($value)) {
            $value = sprintf("%08x",$value);
        }
        if ($register < 32 && $register > 0 && $register == (int)$register)
            $this->registers[$register] = $value;
        else
            throw new Exception("Can't write to supposed register '$register'");
    }
}

function register($register) {
    //Returns register number from name; e.g. $s1 -> 17
    switch($register) {
        case '$zero': return 0; break;
        case '$gp': return 28; break;
        case '$sp': return 29; break;
        case '$fp': return 30; break;
        case '$ra': return 31; break;
        case '$at': return 1; break;
        default:
            $matches = array();
            if(preg_match('/^\$(?<letter>[a-z])(?<number>\d)$/i',$register,$matches)) {
                if ($matches['letter'] == 'v')
                    return $matches['number'] + 2;
                if ($matches['letter'] == 'a')
                    return $matches['number'] + 4;
                if ($matches['letter'] == 't' && (int)$matches['number'] < 8)
                    return $matches['number'] + 8;
                if ($matches['letter'] == 's')
                    return $matches['number'] + 16;
                if ($matches['letter'] == 'k')
                    return $matches['number'] + 26;
                if ($matches['letter'] == 't')
                    return $matches['number'] + 16;
            }
    }
}

function instruction_decode($hex) {
    global $debug;
    //Takes instruction, in hex, and returns Instruction object with 
    //type and appropriate byte strings.
    $binary = sprintf("%032b",(hexdec($hex)));
    $instr = new Instruction();
    $instr->opcode = substr($binary,0,6);
        
    switch((int) bindec($instr->opcode)) {
        case 0x0: 
            $instr->type = TYPE_R; 
            $instr->rs = substr($binary, 6, 5);
            $instr->rt = substr($binary, 11, 5);
            $instr->rd = substr($binary, 16, 5);
            $instr->shamt = substr($binary, 21, 5);
            $instr->funct = substr($binary, 26, 6);
            break;
        case 0x5:
        case 0x4:
        case 0x8:
        case 0x9:
        case 0xa:
        case 0xb:
        case 0xc:
        case 0xd:
        case 0xf:
        case 0x28:
        case 0x23:
        case 0x29:
        case 0x2b:
            $instr->type = TYPE_I;
            $instr->rs = substr($binary, 6, 5);
            $instr->rt = substr($binary, 11, 5);
            $instr->imm = substr($binary, 16, 16);
            break;
        case 0x2:    
        case 0x3:
            $instr->type = TYPE_J;
            $instr->address = substr($binary, 6, 26);
            break;
        default: 
            return TYPE_INVALID;
    }
    
    if($hex == "0000000c")
        $instr->type = TYPE_SYSCALL;
    
    @ $instr->opcodedec    = (int) bindec($instr->opcode);
    @ $instr->rsdec        = (int) bindec($instr->rs);
    @ $instr->rtdec        = (int) bindec($instr->rt);     
    @ $instr->rddec        = (int) bindec($instr->rd);     
    @ $instr->shamtdec    = (int) bindec($instr->shamt); 
    @ $instr->functdec    = (int) bindec($instr->funct);
    @ $instr->immdec        = (int) smartbindec($instr->imm);
    @ $instr->addressdec    = (int) bindec($instr->address);
    return $instr;
} //Instruction

class Memory {
    private $memory;

    public function __construct() {
        $this->memory = array();
    }
    public function read($address) {
        if(is_string($address))
            $address = (int) hexdec($address);
        if(!array_key_exists($address,$this->memory))
            throw new Exception("Memory address undefined: 0x".sprintf("%08x",$address)
                .". (Type: ".gettype($address).")");
        return $this->memory[$address];
    }
    public function comment($address) {
        if(is_string($address))
            $address = (int) hexdec($address);
        if(!array_key_exists($address,$this->memory))
            throw new Exception("Memory address undefined: 0x".sprintf("%08x",$address)
                .". (Type: ".gettype($address).")");
        return $this->memory[$address."_comment"];
    }
    public function write($address, $value, $comment = '') { 
        if(is_string($address))
            $address = (int) hexdec($address);
        if(is_string($value) && strlen($value) > 15) {
            $debug .= "$value > ";
            $value = sprintf("%08x", (int) bindec($value));
            $debug .= "$value\n";
        } elseif(is_int($value)) {
            $value = sprintf("%08x",$value);
        }
        $this->memory[$address] = $value;
        $this->memory[$address."_comment"] = $comment;
    }
} //Memory


//For asciiz... convert \t, \n, etc
function decode_printable_string($string) {
    return str_replace(array("\n", "\t", "\r"),
        array('\n', '\t', '\r'),
        $string);
}
function make_string_printable($string) {
    return str_replace(array('\n', '\t', '\r'),
        array("\n", "\t", "\r"),
        $string);
}
//For array_walk
function trim_self(&$string) { $string = trim($string); }

//hex string to 32-bit binary string (2's complement-agnostic)
function hexbin($hexStr) {
    return sprintf("%032b",hexdec($hexStr));
}
//hex string to integer, allowing 2's complement
function smarthexdec($hexStr) {
    return smartbindec(sprintf("%032b",hexdec($hexStr)));
}
//Zero-Extends a 32-bit-length binary string
function bin32($bin) {
    return sprintf("%032s",$bin);
}
//Sign-extends to 32-to-bit-length binary string
function signext32($bin) {
    if(substr($bin,0,1) =="1")
        return sprintf("%'132s",$bin);
    else
        return sprintf("%032s",$bin);
}
function twoscomp($bin) {
    $out = "";
    $mode = "init";
    for($x = strlen($bin)-1; $x >= 0; $x--) {
        if ($mode != "init")
            $out = ($bin[$x] == "0" ? "1" : "0").$out;
        else {
            if($bin[$x] == "1") {
                $out = "1".$out;
                $mode = "invert";
            }
            else
                $out = "0".$out;
        }
    }
    return $out;
}
function smartbindec($bin) {
    if($bin[0] == 1)
        return -1 * bindec(twoscomp($bin));
    else return (int) bindec($bin);
}



//////////////////////////////
/////// actual parsing ///////
//////////////////////////////

if (strlen($_POST['memInput'])) {
    //Process input!
    $memInput = $_POST['memInput'];
    if (get_magic_quotes_gpc()) {
        $memInput = stripslashes($memInput);
    }
    
    //convert to arroy of lines    
    $inArray = explode("\n", $memInput);
    array_walk($inArray, 'trim_self');
    
    //pc can be overridden by <main>
    $pc = ADDRESS_START; /////// important ///////
    
    //// Put everything in memory
    $mem = new Memory(); /////// important ///////
    $matches = array();
    foreach($inArray as $line) {
        if(preg_match('/(?P<addr>[a-f0-9]{8})\s*:\s*(?P<data>[a-f0-9]{8})\s*(?P<comment>;.*)?/i',$line,$matches)) {
            if(array_key_exists('comment',$matches))
                $mem->write($matches['addr'], $matches['data'], $matches['comment']);
            else
                $mem->write($matches['addr'], $matches['data']);
            //if($_POST['debug_on']) 
            //    $debug .= "addr: ".$matches['addr']."  data: ".$matches['data']."\n";
        }
        else if(preg_match('/^(?P<addr>[a-f0-9]{8})\s*:\s*<main>\s*;.*$/i',$line,$matches))
            $pc = hexdec($matches['addr']);
        else if($_POST['debug_on']) 
            $debug .= "Failed regex: ".$line."\n";
    }
    ///key inputs:
    $userEntered = explode("\n", $_POST['keyInput']);
    array_walk($userEntered, 'trim_self');
    $userEnteredVariablesRead = 0;
    
    
    
    /////BEGIN execution
    //$pc already declared; /////// important ///////
    $reg = new Registers(); /////// important ///////
    //$mem already declared: new Memory();
    
    $execute = true;
    $instrCounter = 0;
    while($execute && ($instrCounter++ < 80000)) { //TODO //DEBUG //This stops baaad recursion
        try {
            $instr = instruction_decode($mem->read($pc));
            if($_POST['debug_on']) {
                $debug .= "Loading 0x".sprintf("%08x : %s  ",$pc,$mem->read($pc));
                //$debug .= print_r($instr,true)."\n";
                $debug .= $mem->comment($pc)."\n";
                if($pc == 0x400000) 
                    $debug .= sprintf('$a0: %d, $a1: %d, $ra: 0x%08s', 
                        $reg->read('$a0'),
                        $reg->read('$a1'),
                        $reg->read('$ra')
                    )."\n";
            }
                
            $pc += 4; //Can be overwritten
            
            if($instr->type == TYPE_R) {
                switch($instr->functdec) {
                    case 0x20: //add
                        $reg->write($instr->rddec,
                            smarthexdec($reg->read($instr->rsdec)) + smarthexdec($reg->read($instr->rtdec))
                            );
                        if($_POST['debug_on'])
                            $debug.= sprintf("\tAdd: \$rs [%08s] + \$rs [%08s] = %08x\n",
                                $reg->read($instr->rsdec),
                                $reg->read($instr->rtdec),    
                                smarthexdec($reg->read($instr->rsdec))+smarthexdec($reg->read($instr->rtdec)));
                        break;
                    case 0x25: //or
                        $reg->write($instr->rddec,
                            hexbin($reg->read($instr->rsdec)) | hexbin($reg->read($instr->rtdec))
                            );
                        break;
                    case 0x0: // sll
                        $reg->write($instr->rddec,
                            hexdec($reg->read($instr->rtdec)) * pow(2,$instr->shamtdec)
                            );
                        break;
                    case 0x2: // srl
                        
                        break;
                    case 0x8: //jr
                        $jumpto = (int)hexdec($reg->read($instr->rsdec));
                        if($jumpto) {
                            $pc = $jumpto;
                            continue;
                        } else {
                            $execute = false;
                            $output.="\n* Program execution complete *";
                        }
                            
                        break;
/*                    case 0xa: 
                        
                        break;
/*                    case 0xb:
                        
                        break; 
/*                    case 0xc:
                        
                        break;
/*                    case 0xd:
                        
                        break;
                    case 0xf:
                        
                        break;
                        */
                    default:
                        $execute = false;
                        $debug .= "Unsupported R-type instruction \n";
                        break;
                }
            } elseif($instr->type == TYPE_I) {
                switch($instr->opcodedec) {
                    case 0x4: //beq, branch on equal
                        if(hexdec($reg->read($instr->rsdec)) == hexdec($reg->read($instr->rtdec))) {
                            $pc += $instr->immdec * 4;
                            if($_POST['debug_on'])
                                $debug.="\tBranching [beq], because ".hexdec($reg->read($instr->rsdec))
                                    ." does equal ".hexdec($reg->read($instr->rtdec))."\n";
                        } else if($_POST['debug_on'])
                            $debug.="\tNOT branching [beq], because ".hexdec($reg->read($instr->rsdec))
                                ." does not equal ".hexdec($reg->read($instr->rtdec))."\n";
                        break;
                    case 0x5: //bne, branch on not equal
                        if(hexdec($reg->read($instr->rsdec)) != hexdec($reg->read($instr->rtdec))) {
                            $pc += $instr->immdec * 4;
                            if($_POST['debug_on'])
                                $debug.="\tBranching [bne], because ".hexdec($reg->read($instr->rsdec))
                                    ." does not equal ".hexdec($reg->read($instr->rtdec))."\n";
                        } else if($_POST['debug_on'])
                            $debug.="\tNOT branching [bne], because ".hexdec($reg->read($instr->rsdec))
                                ." does equal ".hexdec($reg->read($instr->rtdec))."\n";
                        break;
//                    case 0x4:
//                        break;
                    case 0x8: //addi [partially tested]
                        $reg->write($instr->rtdec,
                            smartbindec(hexbin($reg->read($instr->rsdec))) + smartbindec($instr->imm)
                            );
                        if($_POST['debug_on'])
                            $debug.= "   Addi: [".$reg->read($instr->rsdec)
                            ."] ".smartbindec(hexbin($reg->read($instr->rsdec)))." + [imm] "
                            .smartbindec($instr->imm)." = "
                            .(smartbindec(hexbin($reg->read($instr->rsdec))) 
                                + smartbindec($instr->imm))." \n";
                        break;
                    case 0x9: //addiu
                    $reg->write($instr->rtdec,
                        bindec(hexbin($reg->read($instr->rsdec))) + bindec($instr->imm)
                        );
                        break;
//                    case 0xa: 
//                        break;
//                    case 0xb:
//                        break; 
                    case 0xc: //andi
                        $reg->write($instr->rtdec,
                            hexbin($reg->read($instr->rsdec)) & bin32($instr->imm)
                            );
                        break;
                    case 0xd: //ori
                        $reg->write($instr->rtdec,
                            hexbin($reg->read($instr->rsdec)) | bin32($instr->imm)
                            );
                        break;
//                    case 0xf:
//                        break;
//                    case 0x28:
//                        break;
//                    case 0x29:
//                        break;
                    case 0x23: //lw
                        $reg->write($instr->rtdec,
                            $mem->read( hexdec($reg->read($instr->rsdec)) + $instr->immdec)
                            );
                        if($_POST['debug_on'])
                            $debug .= "   Load word: ".sprintf("0x%08x",
                            hexdec($reg->read($instr->rsdec)) + $instr->immdec)
                            ." : ".$mem->read( hexdec($reg->read($instr->rsdec)) + $instr->immdec)."\n";
                        break;
                    case 0x2b: //sw
                        $mem->write( hexdec($reg->read($instr->rsdec)) + $instr->immdec,
                            $reg->read($instr->rtdec));
                        break;
                    default:
                        $execute = false;
                        $debug .= "Unsupported I-type instruction \n";
                        break;
                }
            } elseif($instr->type == TYPE_J) {
                switch($instr->opcodedec) {
                    case 0x3: //jal
                        $reg->write('$ra',$pc);
                        //fall through
                    case 0x2: //jump
                        $pc = (int)floor($pc/0x10000000)*0x10000000 + $instr->addressdec * 4;
                        if($_POST['debug_on']) 
                            $debug .= sprintf("Jumping to 0x%08x\n", $pc);
                        break;
                    default:
                        $debug .= "Unsupported J-type instruction \n";
                        break;
                }
            } elseif($instr->type == TYPE_SYSCALL) {
                if(hexdec($reg->read('$v0')) == 1) { //Print integer
                    $output .=  smarthexdec($reg->read('$a0'));
                }
                elseif(hexdec($reg->read('$v0')) == 4) { //Print string (asciiz)
                    $temp = ""; $words = 0;
                    while(1){
                        //$debug.="Read mem addr: [0x".$reg->read(register('$a0'))
                        //    ."] ".hexdec($reg->read(register('$a0')))
                        //    ." + $words*4 = ".(hexdec($reg->read(register('$a0'))) + $words*4)."\n";//debug
                        $word = $mem->read(
                            hexdec($reg->read(register('$a0')))
                            + $words*4);
                        for($i = 0; $i < 4; $i++) {
                            $ascii = (int) hexdec(substr($word,$i*2,2));
                            if ($ascii == 0) break(2);
                            else $temp .= chr($ascii);
                        }
                        $words++;
                    }
                    $output .=  $temp;
                } elseif (hexdec($reg->read('$v0')) == 5) {
                    if(count($userEntered)==$userEnteredVariablesRead)
                        throw new Exception("Program demands more user inputs!");
                    $reg->write('$v0', (int)trim($userEntered[$userEnteredVariablesRead]));
                    $output .= ((int)trim($userEntered[$userEnteredVariablesRead]))."\n";
                    $userEnteredVariablesRead++;
                } elseif (hexdec($reg->read('$v0')) == 0) {    
                    $execute = false;
                    $output.="\n* Program execution complete *";
                } else {
                    throw new Exception("Unsupported syscall. \$v0: "
                        .$reg->read('$v0')." / ".hexdec($reg->read('$v0')));
                }
            } elseif($instr->type == TYPE_INVALID) {
                $execute = false;
            }
            
        }//try
        catch(Exception $e) {
            $execute = false;
            $debug .= "Failed simulation: ".$e->getMessage()." [program counter: 0x".dechex($pc)."]\n";
        }
        
    }

//END
}

//$output .= "\ntest: ".hexbin("2f")."\n"; //debug
//$output .= "test: ".decbin(hexdec("2f"))."\n"; //debug



// REPLACE with your own <html> tag, <head></head>, <body>, and heading!
//ASU Include (ASU/School theme)
// print asuinclude_top();
?>
    <title>MIPhpS: Online MIPS simulator</title>
    <link rel="stylesheet" type="text/css" href="mips.css" />
    
<?php    
// YOUR TITLE HERE
// print asuinclude_headToContentTitle('MIPhpS: Online MIPS Simulator <span style="font-weight: normal">v'.SIMULATOR_VERSION.'</span>',
// 'Alan Hogan&rsquo;s project for CSE 230 at ASU');


if (!array_key_exists('source', $_GET)) {

?>
<?php if(strlen(trim($debug))) { ?>

<div style="border: 1px solid #c00; padding: .5em;"><?php print nl2br(str_replace(array(' ',"\t"), array('&nbsp;','&nbsp;&nbsp;&nbsp;'), htmlentities($debug))); ?></div>
<?php
}

if (strlen($output)) {
    print '<h3>Simulator Output</h3><div class="code">'.nl2br(htmlentities($output)).'</div>';
}
?>    

<h3>Memory input </h3>
<p>Please enter MIPS binary below. It should include address-instruction pairs in hexadecimal, formatted with a colon between the address and the data (instruction).  Instructions should be separated with a newline.  Address-data pairs are OK too. Semicolon indicates comment.  Then, if your code requires any inputs to be submitted, please enter them, separated by newlines, in the "input" box. (This code is only guaranteed to work with the Ackermann function from <a href="assembler.php">assembler.php</a>.) Or, 
    view <?php print '<a href="'.$_ENV['SCRIPT_URL'].'?source" title="View source code for this page">source code</a>'; ?>.</p>
<?php print '<form action="'.$_ENV['SCRIPT_URL'].'" method="post">'; ?>
<textarea name="memInput" id="mips_memInput" style="width: 99.8%; height: 38em;"><?php 
    print ( 
        (strlen($memInput) > 1)
        ? str_replace("\t", '   ',
             htmlentities($memInput))
        : str_replace("\t", '   ', 
            htmlentities(
'00400000: <AckermannFunc> ; <input:24> AckermannFunc:
00400000: 23bdfff8 ; <input:26> addi $sp, $sp, -8
00400004: afb00004 ; <input:28> sw $s0, 4($sp)
00400008: afbf0000 ; <input:30> sw $ra, 0($sp)
0040000c: <LABEL_IF> ; <input:34> LABEL_IF: # check whether m==0
0040000c: 14800002 ; <input:36> bne $a0, $zero, LABEL_ELSE_IF
00400010: 20a20001 ; <input:39> addi $v0, $a1, 1
00400014: 08100012 ; <input:42> j LABEL_DONE
00400018: <LABEL_ELSE_IF> ; <input:45> LABEL_ELSE_IF:
00400018: 14a00004 ; <input:48> bne $a1, $zero, LABEL_ELSE
0040001c: 2084ffff ; <input:52> addi $a0, $a0, -1
00400020: 20050001 ; <input:53> addi $a1, $zero, 1
00400024: 0c100000 ; <input:57> jal AckermannFunc
00400028: 08100012 ; <input:61> j LABEL_DONE
0040002c: <LABEL_ELSE> ; <input:63> LABEL_ELSE: # This block may be a bit tricky !
0040002c: 00808020 ; <input:67> add $s0, $a0, $zero
00400030: 20a5ffff ; <input:70> addi $a1, $a1, -1
00400034: 0c100000 ; <input:71> jal AckermannFunc
00400038: 2204ffff ; <input:76> addi $a0, $s0, -1
0040003c: 00402820 ; <input:77> add $a1, $v0, $zero
00400040: 0c100000 ; <input:78> jal AckermannFunc
00400044: 08100012 ; <input:81> j LABEL_DONE
00400048: <LABEL_DONE> ; <input:83> LABEL_DONE:
00400048: 8fb00004 ; <input:87> lw $s0, 4($sp)
0040004c: 8fbf0000 ; <input:89> lw $ra, 0($sp)
00400050: 23bd0008 ; <input:91> addi $sp, $sp, 8
00400054: 03e00008 ; <input:94> jr $ra
00400058: <Print> ; <input:111> Print:
00400058: 23bdfffc ; <input:113> addi $sp, $sp, -4 # make space on stack
0040005c: afa40000 ; <input:114> sw $a0, 0($sp) # preserve first parameter m;
00400060: 240400c0 ; <input:116> la $a0, msg1 # load address of msg1
00400064: 00042400 ; <input:116> la $a0, msg1 # load address of msg1
00400068: 24840008 ; <input:116> la $a0, msg1 # load address of msg1
0040006c: 34020004 ; <input:117> li $v0, 4 # load the "print string" syscall number
00400070: 0000000c ; <input:118> syscall
00400074: 8fa40000 ; <input:120> lw $a0, 0($sp) # load first parameter = m
00400078: 34020001 ; <input:121> li $v0, 1 # load the "print integer" syscall number
0040007c: 0000000c ; <input:122> syscall
00400080: 240400c0 ; <input:124> la $a0, comma # load address of comma
00400084: 00042400 ; <input:124> la $a0, comma # load address of comma
00400088: 24840018 ; <input:124> la $a0, comma # load address of comma
0040008c: 34020004 ; <input:125> li $v0, 4 # load the "print string" syscall number
00400090: 0000000c ; <input:126> syscall
00400094: 00052020 ; <input:128> move $a0,$a1 # load second parameter = n
00400098: 34020001 ; <input:129> li $v0, 1 # load the "print integer" syscall number
0040009c: 0000000c ; <input:130> syscall
004000a0: 240400c0 ; <input:133> la $a0, msg2 # load address of msg2
004000a4: 00042400 ; <input:133> la $a0, msg2 # load address of msg2
004000a8: 24840014 ; <input:133> la $a0, msg2 # load address of msg2
004000ac: 34020004 ; <input:134> li $v0, 4 # load the "print string" syscall number
004000b0: 0000000c ; <input:135> syscall
004000b4: 00062020 ; <input:137> move $a0, $a2 # load third parameter = value
004000b8: 34020001 ; <input:138> li $v0, 1 # load the "print integer" syscall number
004000bc: 0000000c ; <input:139> syscall
004000c0: 240400c0 ; <input:141> la $a0, endl # load address of endl
004000c4: 00042400 ; <input:141> la $a0, endl # load address of endl
004000c8: 2484001c ; <input:141> la $a0, endl # load address of endl
004000cc: 34020004 ; <input:142> li $v0, 4 # load the "print string" syscall number
004000d0: 0000000c ; <input:143> syscall
004000d4: 8fa40000 ; <input:146> lw $a0, 0($sp) # restore first parameter
004000d8: 23bd0004 ; <input:147> addi $sp, $sp, 4 # restore stack pointer
004000dc: 03e00008 ; <input:149> jr $ra # return
004000e0: <main> ; <input:154> main:
004000e0: 23bdfff0 ; <input:155> addi $sp, $sp, -16 # make space on stack.
004000e4: afbf0000 ; <input:156> sw $ra, 0($sp) # preserve return address.
004000e8: afb00004 ; <input:157> sw $s0, 4($sp) # preserve registers s0 through s2
004000ec: afb10008 ; <input:158> sw $s1, 8($sp) # as we may clobber it in main
004000f0: afb2000c ; <input:159> sw $s2, 12($sp)
004000f4: 240400c0 ; <input:162> la $a0, prompt_m # first parameter = prompt
004000f8: 00042400 ; <input:162> la $a0, prompt_m # first parameter = prompt
004000fc: 24840000 ; <input:162> la $a0, prompt_m # first parameter = prompt
00400100: 34020004 ; <input:163> li $v0, 4 # load the "print string" syscall number
00400104: 0000000c ; <input:164> syscall
00400108: 34020005 ; <input:166> li $v0, 5 # load the "read integer" syscall number
0040010c: 0000000c ; <input:167> syscall
00400110: 00028020 ; <input:168> move $s0, $v0 # m = s0 = value returned in v0
00400114: 240400c0 ; <input:171> la $a0, prompt_n # second parameter = prompt
00400118: 00042400 ; <input:171> la $a0, prompt_n # second parameter = prompt
0040011c: 24840004 ; <input:171> la $a0, prompt_n # second parameter = prompt
00400120: 34020004 ; <input:172> li $v0, 4 # load the "print string" syscall number
00400124: 0000000c ; <input:173> syscall
00400128: 34020005 ; <input:175> li $v0, 5 # load the "read integer" syscall number
0040012c: 0000000c ; <input:176> syscall
00400130: 00028820 ; <input:177> move $s1, $v0 # n = s1 = value returned in v0
00400134: 00102020 ; <input:180> move $a0, $s0 # first parameter = m
00400138: 00112820 ; <input:181> move $a1, $s1 # second parameter = n
0040013c: 0c100000 ; <input:183> jal AckermannFunc
00400140: 00029020 ; <input:184> move $s2, $v0 # Ackermann value = s2 = value returned
00400144: 00102020 ; <input:187> move $a0, $s0 # first parameter = m
00400148: 00112820 ; <input:188> move $a1, $s1 # second parameter = n
0040014c: 00123020 ; <input:189> move $a2, $s2 #
00400150: 0c100016 ; <input:191> jal Print
00400154: 34020000 ; <input:192> li $v0, 0 # return value for main
00400158: 8fbf0000 ; <input:194> lw $ra, 0($sp) # restore return address
0040015c: 8fb00004 ; <input:195> lw $s0, 4($sp) # restore registers s0 through s3
00400160: 8fb10008 ; <input:196> lw $s1, 8($sp) # before exiting main
00400164: 8fb2000c ; <input:197> lw $s2, 12($sp)
00400168: 23bd0010 ; <input:198> addi $sp, $sp, 16 # restore stack pointer
0040016c: 03e00008 ; <input:200> jr $ra # return to Operating System
;
; DATA IN MEMORY
; prompt_m
00c00000: 6d3d0000 ; m=
; prompt_n
00c00004: 6e3d0000 ; n=
; msg1
00c00008: 41636b65 ; Acke
00c0000c: 726d616e ; rman
00c00010: 6e280000 ; n(
; msg2
00c00014: 293d0000 ; )=
; comma
00c00018: 2c000000 ; ,
; endl
00c0001c: 0a000000 ; \n
'))
        ); //ternary / print
?></textarea>
<br />
<label for="mips_keyInput">Input data (e.g. Ackerman parameters), separated by newlines:</label>
<br />
<textarea name="keyInput" id="mips_keyInput" style="width: 99.8%; height: 6em;"><?php
print htmlentities((strlen($_POST['keyInput']) ? $_POST['keyInput'] : "2\n2"));
?></textarea>
<br />
    <input type="checkbox" name="debug_on" <?php print ($_POST['debug_on'] ? 'checked="checked" ' : ''); ?> id="checkdb" />
    <label for="checkdb">Verbose/debug mode</label>
<br />
<input type="submit" name="simulate" value="Simulate Execution" />
</form>
<?php
} else {

?>
<h2>Source Code</h2>
<p>Seen enough? <?php print '<a href="'.$_ENV['SCRIPT_URL'].'" title="Reset to usable mode">Use Program</a>'; ?>.</p>
<?php
print '<div style="border: 1px solid black; color: black; font-family: monaco, \'Lucida Console\', monospace; background: #eee none; padding: .6em; font-size: 105%;">'.highlight_file(__FILE__,true).'</div>';
}

// REPLACE with your own footer and </body></html> tags
// print asuinclude_finished();
